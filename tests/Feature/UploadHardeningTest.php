<?php

namespace Tests\Feature;

use App\Jobs\ProcessUploadedDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Models\UserPdfMonthlyUsage;
use App\Services\PdfUploadProbe;
use App\Services\PythonRunner;
use App\Support\BlankPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploads: an allowance that cannot be raced past, one for guests too, a PDF
 * that is checked before anything is stored or queued, and a blank document
 * that needs no Python. Uses the project's Python with PyMuPDF for the probe.
 */
class UploadHardeningTest extends TestCase
{
    use RefreshDatabase;

    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diskRoot = sys_get_temp_dir().'/netkit_upload_hardening_'.bin2hex(random_bytes(6));
        File::makeDirectory($this->diskRoot, 0700, true);
        config(['filesystems.disks.local.root' => $this->diskRoot, 'editor_limits.scale' => 1000]);
        Storage::forgetDisk('local');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        File::deleteDirectory($this->diskRoot);

        parent::tearDown();
    }

    public function test_the_monthly_allowance_is_taken_atomically_and_only_by_uploads_that_go_ahead(): void
    {
        config(['pdf_editor.uploads.monthly_limit' => 3]);
        $user = User::factory()->create();
        $count = fn () => (int) UserPdfMonthlyUsage::where('user_id', $user->id)->value('uploads_count');

        $this->upload($this->invoice('first.pdf'), $user)->assertRedirect();
        $this->assertSame(1, $count());

        // The duplicate-name prompt and a refused file used to cost an upload each.
        $this->upload($this->invoice('first.pdf'), $user)->assertStatus(409)->assertJson(['duplicate_name' => true]);
        $this->upload($this->truncatedPdf('broken.pdf'), $user)->assertStatus(422);
        $this->assertSame(1, $count());

        $this->upload($this->invoice('second.pdf'), $user)->assertRedirect();
        // One short of the limit: the check and the increment are one statement,
        // so whatever the interleaving only one request can take the last place.
        $this->assertSame(2, $count());
        $this->upload($this->invoice('third.pdf'), $user)->assertRedirect();
        $refused = $this->upload($this->invoice('fourth.pdf'), $user);
        $refused->assertStatus(429)->assertJson(['success' => false, 'code' => 'monthly_upload_limit']);
        $this->assertStringContainsString('Monthly PDF upload limit reached (3)', $refused->json('message'), 'the documents page opens its plans dialog on this text');
        $this->assertSame(3, $count(), 'never past the limit');
        $this->assertSame(3, Document::where('user_id', $user->id)->count());

        // Blank documents and templates draw on the same allowance.
        $this->actingAs($user)->from(route('documents.index'))->post(route('documents.createBlank'), ['page_size' => 'A4'])
            ->assertRedirect(route('documents.index'))->assertSessionHasErrors('document');

        $this->travelTo(now()->addMonth()->startOfMonth()->addDay());
        $this->upload($this->invoice('next-month.pdf'), $user)->assertRedirect();
    }

    public function test_a_guest_has_a_daily_allowance_per_session_and_per_address(): void
    {
        config(['pdf_editor.uploads.guest_daily_limit' => 2, 'pdf_editor.uploads.guest_daily_limit_per_ip' => 3]);
        // JSON test requests only carry cookies when asked to.
        $as = fn (string $session) => $this->withCredentials()->withCookie(config('session.cookie'), str_repeat($session, 40));

        $as('a')->postJson(route('documents.store'), ['document' => $this->invoice('g1.pdf')])->assertRedirect();
        $as('a')->postJson(route('documents.store'), ['document' => $this->invoice('g2.pdf')])->assertRedirect();
        $refused = $as('a')->postJson(route('documents.store'), ['document' => $this->invoice('g3.pdf')]);
        $refused->assertStatus(429)->assertJson(['code' => 'guest_upload_limit']);
        $this->assertStringContainsString('create a free account', $refused->json('message'));

        // Dropping the cookie gets one more, then the address limit holds.
        $as('b')->postJson(route('documents.store'), ['document' => $this->invoice('g4.pdf')])->assertRedirect();
        $as('c')->postJson(route('documents.store'), ['document' => $this->invoice('g5.pdf')])->assertStatus(429);
        $this->assertSame(3, Document::count());

        // Another address, and an account, are not affected; tomorrow is a new day.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->postJson(route('documents.store'), ['document' => $this->invoice('g6.pdf')])->assertRedirect();
        $this->upload($this->invoice('account.pdf'), User::factory()->create())->assertRedirect();
        $this->travel(1)->days();
        $as('a')->postJson(route('documents.store'), ['document' => $this->invoice('g7.pdf')])->assertRedirect();
    }

    public function test_a_pdf_the_editor_cannot_work_with_is_refused_before_anything_is_stored_or_queued(): void
    {
        config(['pdf_editor.uploads.max_pages' => 3]);
        $user = User::factory()->create();

        $cases = [
            'unreadable' => [$this->truncatedPdf('cut-off.pdf'), 'could not be read'],
            'needs_password' => [$this->generatedPdf('locked.pdf', pages: 1, password: 'secret'), 'protected with a password'],
            'too_many_pages' => [$this->generatedPdf('long.pdf', pages: 4), 'has 4 pages; the editor takes up to 3'],
        ];
        foreach ($cases as $code => [$file, $message]) {
            $response = $this->upload($file, $user);
            $response->assertStatus(422)->assertJson(['success' => false, 'code' => $code]);
            $this->assertStringContainsString($message, $response->json('message'), $code);
            $this->assertSame($response->json('message'), $response->json('errors.document.0'), 'the upload form reads errors.document');
        }

        // A renamed text file does not get as far as the parser.
        $this->upload(UploadedFile::fake()->createWithContent('notes.pdf', "just some text\n"), $user)->assertStatus(422);

        $this->assertSame(0, Document::count());
        $this->assertSame([], Storage::allFiles('documents'), 'nothing was stored');
        Queue::assertNothingPushed();
        $this->assertSame(0, (int) UserPdfMonthlyUsage::where('user_id', $user->id)->value('uploads_count'), 'a refused file costs no allowance');

        // An owner-password PDF opens without a password and is accepted, as is one at the page limit.
        $this->upload($this->generatedPdf('owner-locked.pdf', pages: 3, ownerPassword: 'owner'), $user)->assertRedirect();
        Queue::assertPushed(ProcessUploadedDocumentJob::class, 1);
        $this->assertSame('queued', Document::firstOrFail()->fresh()->processing_status);
    }

    public function test_a_pdf_that_hangs_the_parser_is_refused_within_the_probe_timeout(): void
    {
        $hangingPython = $this->diskRoot.'/hanging-python';
        file_put_contents($hangingPython, "#!/bin/sh\n[ \"$1\" = \"-c\" ] && exit 0\nexec sleep 60\n");
        chmod($hangingPython, 0755);
        config(['python.binary' => $hangingPython, 'python.timeouts.probe_pdf' => 1]);

        $file = $this->invoice('slow.pdf');   // held in a variable: the fake's temp file goes with the object
        $started = microtime(true);
        $refusal = app(PdfUploadProbe::class)->check($file->getRealPath());

        $this->assertLessThan(8, microtime(true) - $started);
        $this->assertSame('too_complex', $refusal['code']);
    }

    public function test_a_blank_document_needs_no_python_and_is_a_valid_pdf(): void
    {
        $realRunner = app(PythonRunner::class);
        $this->mock(PythonRunner::class, fn ($mock) => $mock->shouldNotReceive('exec', 'run', 'shell', 'shellExec'));
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('documents.createBlank'), ['page_size' => 'Letter', 'orientation' => 'landscape']);

        $document = Document::where('user_id', $user->id)->firstOrFail();
        $response->assertRedirect(route('documents.editPdfjs', $document));
        $this->assertSame('Blank Letter Landscape.pdf', $document->original_name);
        $this->assertTrue(Storage::exists($document->original_backup_path), 'restore-original still has its copy');
        $this->assertSame(1, (int) UserPdfMonthlyUsage::where('user_id', $user->id)->value('uploads_count'));

        // Read back by PyMuPDF: one page of the size asked for.
        $this->app->instance(PythonRunner::class, $realRunner);
        $result = $realRunner->run([
            $realRunner->interpreter('fitz'), '-c',
            'import fitz,sys,json; d=fitz.open(sys.argv[1]); r=d[0].rect; print(json.dumps([d.page_count, round(r.width,2), round(r.height,2), d[0].get_text().strip()]))',
            Storage::path($document->path),
        ]);
        $this->assertSame([1, 792.0, 612.0, ''], json_decode(trim($result->stdout), true), $result->output);
        $this->assertNull((new PdfUploadProbe($realRunner))->check(Storage::path($document->path)));

        foreach (['A4' => [595.28, 841.89], 'A3' => [841.89, 1190.55]] as $size => [$width, $height]) {
            $this->assertStringContainsString("/MediaBox [0 0 {$width} {$height}]", BlankPdf::make($width, $height), $size);
        }
    }

    private function upload(UploadedFile $file, User $user)
    {
        return $this->actingAs($user)->postJson(route('documents.store'), ['document' => $file]);
    }

    private function invoice(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/OverlayEditor/invoicesample.pdf')));
    }

    /** The first 400 bytes of a real PDF: the header is there, the rest is not. */
    private function truncatedPdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, substr(file_get_contents(base_path('tests/OverlayEditor/invoicesample.pdf')), 0, 400));
    }

    private function generatedPdf(string $name, int $pages, ?string $password = null, ?string $ownerPassword = null): UploadedFile
    {
        $path = $this->diskRoot.'/'.bin2hex(random_bytes(4)).'.pdf';
        $runner = app(PythonRunner::class);
        $script = <<<'PY'
            import fitz, sys
            dest, pages, user_pw, owner_pw = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4]
            doc = fitz.open()
            for i in range(pages):
                doc.new_page().insert_text((72, 100), f"Page {i + 1}")
            if user_pw or owner_pw:
                doc.save(dest, encryption=fitz.PDF_ENCRYPT_AES_256, user_pw=user_pw, owner_pw=owner_pw or user_pw)
            else:
                doc.save(dest)
            PY;
        $script = implode("\n", array_map(fn ($line) => preg_replace('/^ {12}/', '', $line), explode("\n", $script)));
        $result = $runner->run([$runner->interpreter('fitz'), '-c', $script, $path, (string) $pages, (string) $password, (string) $ownerPassword]);
        $this->assertTrue($result->ok(), $result->output);

        return UploadedFile::fake()->createWithContent($name, file_get_contents($path));
    }
}
