<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentAccess;
use App\Services\GuestDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Documents of visitors without an account: owned through a guest token with
 * a bounded list and a lifetime of its own, claimed on sign-in, closed to
 * every other visitor, and removed once nobody can open them any more.
 */
class GuestDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diskRoot = sys_get_temp_dir().'/netkit_guest_documents_'.bin2hex(random_bytes(6));
        File::makeDirectory($this->diskRoot, 0700, true);
        config(['filesystems.disks.local.root' => $this->diskRoot, 'editor_limits.scale' => 1000, 'pdf_editor.uploads.guest_daily_limit' => 1000, 'pdf_editor.uploads.guest_daily_limit_per_ip' => 1000]);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        File::deleteDirectory($this->diskRoot);

        parent::tearDown();
    }

    public function test_a_guest_owns_documents_through_a_token_that_outlives_the_session_and_no_one_else_can_open_them(): void
    {
        $created = $this->post(route('documents.createBlank'), ['page_size' => 'A4']);
        $document = Document::firstOrFail();
        $created->assertRedirect(route('documents.editPdfjs', $document));
        $cookie = $created->getCookie(GuestDocuments::COOKIE, decrypt: true);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertGreaterThan(now()->addDays(6)->getTimestamp(), $cookie->getExpiresTime());

        $link = DB::table('guest_documents')->where('document_id', $document->id)->first();
        $this->assertSame(hash('sha256', $cookie->getValue()), $link->guest_token_hash, 'only the hash is stored');
        $this->assertNull(session(DocumentAccess::SESSION_KEY), 'nothing is kept in the session payload any more');

        // A new session (the old one expired after 120 minutes) with the same cookie: still theirs.
        $this->visitor($cookie->getValue())->getJson(route('documents.processing.status', $document))->assertOk();

        // No cookie, another visitor's cookie, a forged value: not found.
        $this->visitor(null)->getJson(route('documents.processing.status', $document))->assertNotFound();
        $this->visitor(str_repeat('x', 40))->getJson(route('documents.processing.status', $document))->assertNotFound();
        $this->visitor('not-a-token')->getJson(route('documents.processing.status', $document))->assertNotFound();
    }

    public function test_the_list_is_capped_at_the_most_recent_documents(): void
    {
        config(['pdf_editor.guests.max_documents' => 3]);
        $token = str_repeat('g', 40);
        $documents = collect(range(1, 5))->map(fn () => $this->unownedDocument());

        foreach ($documents as $document) {
            $this->app->forgetScopedInstances();
            $request = \Illuminate\Http\Request::create('/');
            $request->cookies->set(GuestDocuments::COOKIE, $token);
            app(GuestDocuments::class)->remember($request, $document->id);
        }

        $this->assertSame(3, DB::table('guest_documents')->count());
        $open = fn (Document $document) => $this->visitor($token)->getJson(route('documents.processing.status', $document))->status();
        $this->assertSame([404, 404, 200, 200, 200], $documents->map($open)->all(), 'the three most recent stay reachable');
    }

    public function test_signing_in_claims_the_guest_documents(): void
    {
        $token = str_repeat('c', 40);
        $document = $this->unownedDocument();
        $this->rememberFor($token, $document);
        DB::table('pdf_state')->insert(['document_id' => $document->id, 'session_id' => 's', 'user_email' => 'guest', 'annotation_data' => '{"id":"a"}', 'state' => 'saved', 'created_at' => now(), 'updated_at' => now()]);

        $user = User::factory()->create();
        $this->visitor($token)->actingAs($user)
            ->getJson(route('documents.processing.status', $document))->assertOk();

        $this->assertSame($user->id, (int) $document->fresh()->user_id);
        $this->assertSame($user->id, (int) DB::table('pdf_state')->where('document_id', $document->id)->value('user_id'));
        $this->assertSame(0, DB::table('guest_documents')->where('document_id', $document->id)->count(), 'the account owns it now');

        // It is the account's from now on: the cookie alone no longer opens it, and pruning leaves it be.
        $this->app['auth']->forgetGuards();
        $this->visitor($token)->getJson(route('documents.processing.status', $document))->assertNotFound();
    }

    public function test_a_list_left_in_the_session_by_an_earlier_version_is_honoured_and_carried_over(): void
    {
        $document = $this->unownedDocument();

        $response = $this->withSession([DocumentAccess::SESSION_KEY => [$document->id]])
            ->getJson(route('documents.processing.status', $document));

        $response->assertOk();
        $this->assertSame(1, DB::table('guest_documents')->where('document_id', $document->id)->count());
        $this->assertNotNull($response->getCookie(GuestDocuments::COOKIE, decrypt: true));
        $this->assertNull(session(DocumentAccess::SESSION_KEY));
    }

    public function test_guest_documents_are_removed_once_nobody_can_open_them(): void
    {
        $user = User::factory()->create();
        $make = function (array $attributes, ?string $lastSeen) {
            $document = $this->unownedDocument($attributes);
            Storage::put($document->path, '%PDF-1.4 test');
            if ($lastSeen !== null) {
                $this->rememberFor(str_repeat('p', 40), $document);
                DB::table('guest_documents')->where('document_id', $document->id)->update(['last_seen_at' => $lastSeen]);
            }

            return $document;
        };
        $old = now()->subDays(30);
        $expired = $make(['created_at' => $old], now()->subDays(8));
        $orphaned = $make(['created_at' => $old], null);                       // its session list died long ago
        $active = $make(['created_at' => $old], now()->subDays(2));            // seen within the week
        $fresh = $make(['created_at' => now()->subDay()], null);               // too new to judge
        $owned = $make(['created_at' => $old, 'user_id' => $user->id], null);
        $fixture = $make(['created_at' => $old, 'mode' => 'regression'], null);
        Storage::put("previews/{$expired->id}.jpg", 'preview');
        DB::table('documents')->where('id', $expired->id)->update(['preview_path' => "previews/{$expired->id}.jpg", 'original_backup_path' => 'documents/backup_x.pdf']);
        Storage::put('documents/backup_x.pdf', '%PDF backup');

        $this->artisan('documents:prune-guests', ['--dry-run' => true])->expectsOutputToContain('2 guest documents are past their 7 days')->assertExitCode(0);
        $this->assertSame(6, Document::count());

        $this->artisan('documents:prune-guests')->expectsOutputToContain('Removed 2 documents')->assertExitCode(0);

        $this->assertEqualsCanonicalizing([$active->id, $fresh->id, $owned->id, $fixture->id], Document::withTrashed()->pluck('id')->all());
        foreach ([$expired->path, "previews/{$expired->id}.jpg", 'documents/backup_x.pdf', $orphaned->path] as $path) {
            $this->assertFalse(Storage::exists($path), $path);
        }
        $this->assertTrue(Storage::exists($active->path));
        $this->assertSame(1, DB::table('guest_documents')->count(), 'expired links go too');
    }

    public function test_the_scheduler_only_prunes_where_it_is_switched_on(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'documents:prune-guests'));
        $this->assertNotNull($event);

        config(['pdf_editor.guests.prune' => false]);
        $this->assertFalse($event->filtersPass($this->app), 'a development box keeps its ownerless documents');
        config(['pdf_editor.guests.prune' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_the_documents_page_tells_a_guest_how_long_their_files_are_kept(): void
    {
        $token = str_repeat('n', 40);
        $this->rememberFor($token, $this->unownedDocument());

        $page = $this->visitor($token)->get(route('documents.index'));
        $page->assertOk()->assertSee('kept in this browser for 7 days')->assertSee('Create a free account');

        $this->visitor(null)->actingAs(User::factory()->create())->get(route('documents.index'))->assertDontSee('kept in this browser');
    }

    /**
     * The next request comes from this visitor: a new session, only this
     * guest cookie (JSON test requests send cookies only when asked to), and
     * fresh per-request services, which a real request gets by itself.
     */
    private function visitor(?string $token): static
    {
        $this->flushSession();
        $this->app->forgetScopedInstances();
        $this->defaultCookies = [];
        $this->withCredentials();

        return $token === null ? $this : $this->withCookie(GuestDocuments::COOKIE, $token);
    }

    private function unownedDocument(array $attributes = []): Document
    {
        $document = Document::query()->create([
            'original_name' => 'guest.pdf',
            'path' => 'documents/guest_'.bin2hex(random_bytes(4)).'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);
        if ($attributes !== []) {
            DB::table('documents')->where('id', $document->id)->update($attributes);
        }

        return $document->fresh();
    }

    private function rememberFor(string $token, Document $document): void
    {
        $this->app->forgetScopedInstances();
        $request = \Illuminate\Http\Request::create('/');
        $request->cookies->set(GuestDocuments::COOKIE, $token);
        app(GuestDocuments::class)->remember($request, $document->id);
        $this->app->forgetScopedInstances();
    }
}
