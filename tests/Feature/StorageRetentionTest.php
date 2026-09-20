<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\PdfAnnotationAssetService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What a user puts on a document is private, and what nobody needs any more
 * is removed: annotation assets off the public disk, the retention policy of
 * documents:prune, and a process umask that only development relaxes.
 */
class StorageRetentionTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_images_placed_on_a_document_are_private_and_served_to_its_owner_only(): void
    {
        $owner = User::factory()->create();
        $document = $this->documentFor($owner);
        $assets = app(PdfAnnotationAssetService::class);

        $stored = $assets->normalizeForPersistence($document, ['id' => 'sig-1', 'type' => 'signature', 'src' => self::PNG]);

        $path = $stored['assetPath'];
        $this->assertStringStartsWith("annotation-assets/documents/{$document->id}/", $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);   // nothing under /storage/... to fetch without signing in

        $url = $assets->assetUrl($path);
        $this->assertStringContainsString("/documents/{$document->id}/annotation-assets/", $url);
        $served = $this->actingAs($owner)->get($url);
        $served->assertOk();
        $this->assertStringContainsString('private', (string) $served->headers->get('Cache-Control'), (string) $served->headers->get('Cache-Control'));
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }

    public function test_assets_written_before_the_move_are_still_found_and_the_command_moves_them(): void
    {
        $document = $this->documentFor(User::factory()->create());
        $legacy = "annotation-assets/documents/{$document->id}/pdfjs_old_0123456789abcdef.png";
        Storage::disk('public')->put($legacy, 'legacy image bytes');
        $assets = app(PdfAnnotationAssetService::class);

        $this->assertSame(Storage::disk('public')->path($legacy), $assets->assetAbsolutePath($legacy));

        $this->artisan('documents:migrate-annotation-assets', ['--dry-run' => true])->expectsOutputToContain('Would move 1 files')->assertExitCode(0);
        Storage::disk('public')->assertExists($legacy);

        $this->artisan('documents:migrate-annotation-assets')->expectsOutputToContain('Moved 1 files')->assertExitCode(0);
        Storage::disk('public')->assertMissing($legacy);
        $this->assertSame('legacy image bytes', Storage::disk('local')->get($legacy));
        $this->assertSame(Storage::disk('local')->path($legacy), $assets->assetAbsolutePath($legacy));
        $this->artisan('documents:migrate-annotation-assets')->expectsOutputToContain('Moved 0 files')->assertExitCode(0);

        // Deleting the document for good takes its assets with it, wherever they are.
        app(\App\Services\DocumentRemoval::class)->purge($document);
        Storage::disk('local')->assertMissing($legacy);
    }

    public function test_the_retention_policy_removes_old_trash_orphaned_rows_and_old_previews_only(): void
    {
        config(['pdf_editor.retention.trash_days' => 30]);
        $user = User::factory()->create();
        $kept = $this->documentFor($user, 'kept.pdf');
        $recentlyTrashed = $this->documentFor($user, 'trashed-last-week.pdf');
        $longTrashed = $this->documentFor($user, 'trashed-in-spring.pdf');
        $recentlyTrashed->delete();
        $longTrashed->delete();
        DB::table('documents')->where('id', $recentlyTrashed->id)->update(['deleted_at' => now()->subDays(7)]);
        DB::table('documents')->where('id', $longTrashed->id)->update(['deleted_at' => now()->subDays(31)]);
        Storage::put($longTrashed->path, '%PDF-1.4 old');
        Storage::put($kept->path, '%PDF-1.4 kept');

        foreach ([$kept->id, $recentlyTrashed->id, 999999] as $documentId) {
            DB::table('pdf_extractions_fitz')->insert(['document_id' => $documentId, 'pdf_filename' => 'x.pdf', 'total_pages' => 1, 'total_words' => 0, 'full_text' => '', 'extraction_data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        Storage::disk('public')->put('debug/pdf-state-stamps/old.pdf', 'old');
        Storage::disk('public')->put('debug/pdf-state-stamps/new.pdf', 'new');
        touch(Storage::disk('public')->path('debug/pdf-state-stamps/old.pdf'), now()->subDays(2)->getTimestamp());

        // A dry run changes nothing.
        $this->artisan('documents:prune', ['--dry-run' => true])->expectsOutputToContain('would remove 1 documents')->assertExitCode(0);
        $this->assertNotNull(Document::withTrashed()->find($longTrashed->id));

        $this->artisan('documents:prune')->expectsOutputToContain('removed 1 documents')->assertExitCode(0);

        $this->assertNull(Document::withTrashed()->find($longTrashed->id));
        Storage::assertMissing($longTrashed->path);
        $this->assertNotNull(Document::withTrashed()->find($recentlyTrashed->id), 'a week in the trash is not long enough');
        Storage::assertExists($kept->path);
        $this->assertSame(
            [$kept->id, $recentlyTrashed->id],
            DB::table('pdf_extractions_fitz')->orderBy('document_id')->pluck('document_id')->all(),
            'only the row of a document that no longer exists went; a trashed document keeps its extraction'
        );
        Storage::disk('public')->assertMissing('debug/pdf-state-stamps/old.pdf');
        Storage::disk('public')->assertExists('debug/pdf-state-stamps/new.pdf');

        // The trash says how long it keeps things.
        $this->actingAs($user)->get(route('documents.index', ['view' => 'trash']))->assertOk()->assertSee('deleted for good after 30 days');
    }

    public function test_the_prune_is_scheduled_only_where_it_is_switched_on_and_the_umask_is_relaxed_only_in_sail(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'documents:prune') && ! str_contains($event->command, 'prune-guests'));
        $this->assertNotNull($event, 'documents:prune is scheduled');
        config(['pdf_editor.retention.prune' => false]);
        $this->assertFalse($event->filtersPass($this->app));
        config(['pdf_editor.retention.prune' => true]);
        $this->assertTrue($event->filtersPass($this->app));

        $bootstrap = File::get(base_path('bootstrap/app.php'));
        $this->assertMatchesRegularExpression('/if \(getenv\(\'LARAVEL_SAIL\'\)\) \{\s+umask\(0000\);/', $bootstrap, 'umask(0000) must stay inside the Sail check');
        $this->assertSame(1, substr_count($bootstrap, 'umask('));
        $this->assertStringNotContainsString('LARAVEL_SAIL', File::get(base_path('docker/Dockerfile.prod')));
    }

    private function documentFor(User $user, string $name = 'doc.pdf'): Document
    {
        return Document::query()->create([
            'user_id' => $user->id,
            'original_name' => $name,
            'path' => 'documents/'.bin2hex(random_bytes(6)).'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
        ]);
    }
}
