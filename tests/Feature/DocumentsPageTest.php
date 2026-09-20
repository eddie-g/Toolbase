<?php

namespace Tests\Feature;

use App\Jobs\GenerateDocumentPreviewJob;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentPreviews;
use App\Services\PythonRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The documents page is one page of cards: a bounded number of queries, no
 * preview images in the HTML or in memory, and no Python. Previews are files
 * served from a cacheable route and made by a queued job.
 */
class DocumentsPageTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $diskRoot;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diskRoot = sys_get_temp_dir().'/netkit_documents_page_'.bin2hex(random_bytes(6));
        File::makeDirectory($this->diskRoot, 0700, true);
        config(['filesystems.disks.local.root' => $this->diskRoot]);
        Storage::forgetDisk('local');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        File::deleteDirectory($this->diskRoot);

        parent::tearDown();
    }

    public function test_two_hundred_documents_cost_one_page_of_work_and_no_python(): void
    {
        Queue::fake();
        $this->mock(PythonRunner::class, fn ($mock) => $mock->shouldNotReceive('exec', 'run', 'shell', 'shellExec'));
        $legacyPreview = str_repeat(self::PNG, 300);   // about 28 KB of base64, as the rows used to carry
        $now = now();
        DB::table('documents')->insert(array_map(fn (int $i) => [
            'user_id' => $this->user->id,
            'original_name' => sprintf('Document %03d.pdf', $i),
            'path' => "documents/doc-{$i}.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'preview_image' => $i % 2 === 0 ? $legacyPreview : null,
            'preview_image_mime_type' => $i % 2 === 0 ? 'image/png' : null,
            'preview_image_updated_at' => $i % 2 === 0 ? $now : null,
            'created_at' => $now->copy()->subMinutes(200 - $i),
            'updated_at' => $now->copy()->subMinutes(200 - $i),
        ], range(1, 200)));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $memoryBefore = memory_get_usage();
        $response = $this->actingAs($this->user)->get(route('documents.index'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();
        $html = $response->getContent();
        $this->assertSame(24, substr_count($html, 'class="doc-card"'), 'one page of cards');
        $this->assertStringContainsString('Document 200.pdf', $html, 'newest first');
        $this->assertStringNotContainsString('Document 176.pdf', $html);
        $this->assertStringContainsString('<span class="docs-count">200</span>', $html, 'the count is of all documents, not of the page');
        $this->assertStringContainsString('Page 1 of 9', $html);
        $this->assertStringNotContainsString(self::PNG, $html, 'no preview is inlined');
        $this->assertDoesNotMatchRegularExpression('/class="doc-preview-image"\s+src="data:/', $html);
        $this->assertStringContainsString('/documents/'.Document::where('original_name', 'Document 200.pdf')->value('id').'/preview?v=', $html);
        $this->assertLessThan(600 * 1024, strlen($html));

        $this->assertLessThanOrEqual(12, count($queries), 'a bounded number of queries, whatever the library size');
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('preview_image`,', str_replace('preview_image_', '', $query['query']).',');
            $this->assertDoesNotMatchRegularExpression('/select \* from [`"]documents[`"]/', $query['query']);
        }

        // The twelve cards without a preview each asked for one, once.
        Queue::assertPushed(GenerateDocumentPreviewJob::class, 12);
        Queue::assertPushed(GenerateDocumentPreviewJob::class, fn ($job) => $job->queue === 'pdf-extraction');
        $this->actingAs($this->user)->get(route('documents.index'))->assertOk();
        Queue::assertPushed(GenerateDocumentPreviewJob::class, 12);

        $second = $this->actingAs($this->user)->get(route('documents.index', ['page' => 9]));
        $second->assertOk();
        $this->assertSame(8, substr_count($second->getContent(), 'class="doc-card"'));
        $this->assertStringContainsString('Document 001.pdf', $second->getContent());
        unset($memoryBefore);
    }

    public function test_a_preview_is_a_file_served_with_cache_headers_to_its_owner_only(): void
    {
        $document = $this->makeDocument();
        $previews = app(DocumentPreviews::class);
        $this->assertNull($previews->url($document));
        $this->actingAs($this->user)->get(route('documents.preview', $document))->assertNotFound();

        $updatedAt = $document->updated_at->toDateTimeString();
        $previews->store($document, base64_decode(self::PNG), 'image/png', 1, 1);

        $row = DB::table('documents')->where('id', $document->id)->first();
        $this->assertSame("previews/{$document->id}.png", $row->preview_path);
        $this->assertNull($row->preview_image, 'nothing in the row');
        $this->assertTrue(Storage::exists($row->preview_path));
        $this->assertSame($updatedAt, $document->fresh()->updated_at->toDateTimeString(), 'a new thumbnail does not reorder the list');

        $url = $previews->url($document->fresh());
        $this->assertStringContainsString('?v=', $url);
        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(base64_decode(self::PNG), $response->getContent());
        $this->assertStringContainsString('max-age=31536000', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->actingAs($this->user)->get($url, ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);

        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();

        $previews->clear($document->fresh());
        $this->assertFalse(Storage::exists("previews/{$document->id}.png"));
        $this->assertNull(DB::table('documents')->where('id', $document->id)->value('preview_image_updated_at'));
    }

    public function test_previews_still_in_the_row_are_served_and_moved_to_files(): void
    {
        $lazy = $this->makeDocument(['preview_image' => self::PNG, 'preview_image_mime_type' => 'image/png', 'preview_image_updated_at' => now()->subDay()]);
        $bulk = $this->makeDocument(['preview_image' => self::PNG, 'preview_image_mime_type' => 'image/png', 'preview_image_updated_at' => now()->subDay()]);
        $garbage = $this->makeDocument(['preview_image' => '%%% not base64 %%%', 'preview_image_mime_type' => 'image/png', 'preview_image_updated_at' => now()->subDay()]);
        $urlBefore = app(DocumentPreviews::class)->url($lazy);

        // Asked for: served from the row, and moved as a side effect.
        $response = $this->actingAs($this->user)->get($urlBefore);
        $response->assertOk();
        $this->assertSame(base64_decode(self::PNG), $response->getContent());
        $this->assertNull(DB::table('documents')->where('id', $lazy->id)->value('preview_image'));
        $this->assertTrue(Storage::exists("previews/{$lazy->id}.png"));
        $this->assertSame($urlBefore, app(DocumentPreviews::class)->url($lazy->fresh()), 'the address, and so the browser cache, survives the move');

        $this->artisan('documents:migrate-previews', ['--dry-run' => true])->expectsOutputToContain('2 previews still in the row')->assertExitCode(0);
        $this->assertNotNull(DB::table('documents')->where('id', $bulk->id)->value('preview_image'));

        $this->artisan('documents:migrate-previews')->expectsOutputToContain('1 previews are files now, 1 unreadable')->assertExitCode(0);
        $this->assertSame(0, DB::table('documents')->whereNotNull('preview_image')->count());
        $this->assertTrue(Storage::exists("previews/{$bulk->id}.png"));
        $this->assertFalse(Storage::exists("previews/{$garbage->id}.png"));
        $this->actingAs($this->user)->get(route('documents.preview', $bulk))->assertOk();
    }

    private function makeDocument(array $attributes = []): Document
    {
        $document = Document::query()->create([
            'user_id' => $this->user->id,
            'original_name' => 'preview.pdf',
            'path' => 'documents/preview_'.bin2hex(random_bytes(4)).'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);
        if ($attributes !== []) {
            DB::table('documents')->where('id', $document->id)->update($attributes);
        }

        return $document->fresh();
    }
}
