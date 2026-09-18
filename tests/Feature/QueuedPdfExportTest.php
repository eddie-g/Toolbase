<?php

namespace Tests\Feature;

use App\Http\Controllers\DocumentController;
use App\Jobs\ExportAnnotatedPdfJob;
use App\Models\Document;
use App\Models\PdfExport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The editor's Download is queued: the request stores the payload and enqueues
 * ExportAnnotatedPdfJob, the client polls a status URL and fetches the result
 * through a signed, expiring link. Runs the real exporter, so it needs the
 * project's Python with PyMuPDF (run it in the app container).
 */
class QueuedPdfExportTest extends TestCase
{
    private const QUEUED = ['X-Export-Mode' => 'queued', 'Accept' => 'application/json'];

    private string $testDiskRoot;

    private Document $document;

    private array $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDiskRoot = sys_get_temp_dir().'/netkit_pdf_export_'.bin2hex(random_bytes(8));
        File::makeDirectory($this->testDiskRoot, 0700, true);
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'filesystems.disks.local.root' => $this->testDiskRoot,
            'pdf_export.allow_sync' => true,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Storage::forgetDisk('local');

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('original_name');
            $table->string('path');
            $table->string('original_backup_path')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('mode')->nullable();
            $table->string('pdf_password_hash')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pdf_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->char('payload_hash', 64);
            $table->string('status', 24)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('payload_path');
            $table->string('output_path')->nullable();
            $table->string('download_name')->nullable();
            $table->unsignedBigInteger('output_bytes')->nullable();
            $table->text('error')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        $this->document = $this->makeDocument(file_get_contents(base_path('tests/OverlayEditor/invoicesample.pdf')));
        $this->session = ['pdf_editor_accessible_document_ids' => [$this->document->id]];
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        if (isset($this->testDiskRoot)) {
            File::deleteDirectory($this->testDiskRoot);
        }

        parent::tearDown();
    }

    public function test_download_is_enqueued_and_a_repeated_click_joins_the_export_in_flight(): void
    {
        Queue::fake();

        $first = $this->withSession($this->session)
            ->postJson(route('documents.downloadAnnotatedPdf', $this->document), $this->payload(), self::QUEUED);

        $first->assertStatus(202)->assertJson([
            'success' => true,
            'queued' => true,
            'status' => PdfExport::STATUS_QUEUED,
        ]);
        $export = PdfExport::query()->where('uuid', $first->json('export_id'))->firstOrFail();
        $this->assertSame(
            route('documents.exports.status', [$this->document, $export]),
            $first->json('status_url')
        );
        $this->assertTrue(Storage::exists($export->payload_path));
        $this->assertSame('invoice_annotated.pdf', $export->download_name);

        $second = $this->withSession($this->session)
            ->postJson(route('documents.downloadAnnotatedPdf', $this->document), $this->payload(), self::QUEUED);
        $second->assertStatus(202);
        $this->assertSame($export->uuid, $second->json('export_id'));
        $this->assertSame(1, PdfExport::query()->count());

        Queue::assertPushed(ExportAnnotatedPdfJob::class, 1);
        Queue::assertPushed(
            ExportAnnotatedPdfJob::class,
            fn (ExportAnnotatedPdfJob $job) => $job->exportId === $export->id
                && $job->queue === 'pdf-export'
                && $job->tries === 2
                && $job->failOnTimeout
        );

        // A changed payload is a different export.
        $changed = $this->withSession($this->session)
            ->postJson(
                route('documents.downloadAnnotatedPdf', $this->document),
                $this->payload(['session_id' => 'another-session']),
                self::QUEUED
            );
        $this->assertNotSame($export->uuid, $changed->json('export_id'));
    }

    public function test_worker_generates_the_pdf_and_serves_it_through_a_signed_expiring_link(): void
    {
        $export = $this->queueExport($this->document);
        $workingFilesBefore = $this->workingFiles($this->document);

        $this->runJob($export);

        $export->refresh();
        $this->assertSame(PdfExport::STATUS_COMPLETED, $export->status);
        $this->assertSame(100, $export->progress);
        $this->assertGreaterThan(1000, $export->output_bytes);
        $this->assertTrue($export->expires_at->isFuture());
        $this->assertFalse(Storage::exists($export->payload_path), 'the request payload is dropped once the PDF exists');
        $this->assertSame($workingFilesBefore, $this->workingFiles($this->document), 'no working files left in storage/app/temp');

        $status = $this->withSession($this->session)
            ->getJson(route('documents.exports.status', [$this->document, $export]));
        $status->assertOk()->assertJson([
            'success' => true,
            'status' => PdfExport::STATUS_COMPLETED,
            'progress' => 100,
            'download_name' => 'invoice_annotated.pdf',
        ]);
        $downloadUrl = $status->json('download_url');
        $this->assertStringContainsString('signature=', $downloadUrl);
        $this->assertStringContainsString('expires=', $downloadUrl);

        $download = $this->withSession($this->session)->get($downloadUrl);
        $download->assertOk();
        $this->assertStringStartsWith('%PDF', $download->streamedContent());
        $this->assertStringContainsString('invoice_annotated.pdf', (string) $download->headers->get('content-disposition'));

        // The signature is required, the document access check still applies,
        // and the link dies with the export.
        $this->withSession($this->session)
            ->get(route('documents.exports.download', [$this->document, $export]))
            ->assertStatus(403);
        $this->flushSession();
        $this->get($downloadUrl)->assertStatus(404);
        $this->getJson(route('documents.exports.status', [$this->document, $export]))->assertStatus(404);

        $other = $this->makeDocument('%PDF-1.4 other');
        $this->flushSession();
        $this->withSession(['pdf_editor_accessible_document_ids' => [$other->id]])
            ->getJson(route('documents.exports.status', [$other, $export]))
            ->assertStatus(404);

        $this->travel(20)->minutes();
        $this->flushSession();
        $this->withSession($this->session)->get($downloadUrl)->assertStatus(403);
        $this->withSession($this->session)
            ->getJson(route('documents.exports.status', [$this->document, $export]))
            ->assertOk()
            ->assertJson(['success' => false, 'status' => 'expired'])
            ->assertJsonMissingPath('download_url');
    }

    public function test_failed_export_reports_a_reference_and_never_the_process_output(): void
    {
        $broken = $this->makeDocument('this is not a pdf');
        $session = ['pdf_editor_accessible_document_ids' => [$broken->id]];
        $export = $this->queueExport($broken, $session);
        $workingFilesBefore = $this->workingFiles($broken);

        $this->runJob($export);

        $export->refresh();
        $this->assertSame(PdfExport::STATUS_FAILED, $export->status);
        $this->assertSame('Failed to generate annotated PDF.', $export->error);
        $this->assertFalse(Storage::exists($export->directory()), 'payload and partial output are removed');
        $this->assertSame($workingFilesBefore, $this->workingFiles($broken), 'no working files left in storage/app/temp');

        $status = $this->withSession($session)->getJson(route('documents.exports.status', [$broken, $export]));
        $status->assertOk()->assertJson([
            'success' => false,
            'status' => PdfExport::STATUS_FAILED,
            'message' => 'Failed to generate annotated PDF.',
            'reference' => $export->uuid,
        ]);
        $this->assertLeaksNothing($status->getContent());

        // Same rule for the in-request path the QA tools use.
        $sync = $this->withSession($session)
            ->postJson(route('documents.downloadAnnotatedPdf', $broken), $this->payload());
        $sync->assertStatus(500)->assertJsonStructure(['success', 'message', 'reference']);
        $sync->assertJsonMissingPath('error');
        $this->assertLeaksNothing($sync->getContent());
        $this->assertSame($workingFilesBefore, $this->workingFiles($broken));
    }

    public function test_a_hung_exporter_is_killed_at_its_timeout_and_reported_as_a_timeout(): void
    {
        // Stands in for the interpreter: passes the module probe, then hangs.
        $hangingPython = $this->testDiskRoot.'/hanging-python';
        file_put_contents($hangingPython, "#!/bin/sh\n[ \"$1\" = \"-c\" ] && exit 0\nexec sleep 60\n");
        chmod($hangingPython, 0755);
        config([
            'python.binary' => $hangingPython,
            'python.timeouts.apply_annotations_direct' => 1,
        ]);
        $export = $this->queueExport($this->document);
        $workingFilesBefore = $this->workingFiles($this->document);

        $started = microtime(true);
        $this->runJob($export);

        $this->assertLessThan(10, microtime(true) - $started, 'the process timeout ends the export, not the sleep');
        $export->refresh();
        $this->assertSame(PdfExport::STATUS_FAILED, $export->status);
        $this->assertStringContainsString('took too long', $export->error);
        $this->assertSame($workingFilesBefore, $this->workingFiles($this->document), 'a timeout leaves no working files');

        $this->withSession($this->session)
            ->postJson(route('documents.downloadAnnotatedPdf', $this->document), $this->payload())
            ->assertStatus(504)
            ->assertJsonMissingPath('error');
    }

    public function test_a_worker_that_cannot_get_a_python_slot_puts_the_export_back_on_the_queue(): void
    {
        config(['python.max_concurrent' => 1, 'python.slot_wait_seconds' => 0]);
        $export = $this->queueExport($this->document);
        $workingFilesBefore = $this->workingFiles($this->document);
        $held = \Illuminate\Support\Facades\Cache::store()->lock('python-runner:slot:0', 60);
        $this->assertTrue($held->get());

        try {
            $this->runJob($export);
        } finally {
            $held->release();
        }

        $export->refresh();
        $this->assertSame(PdfExport::STATUS_QUEUED, $export->status, 'busy is not a failure: the job is released for another attempt');
        $this->assertTrue(Storage::exists($export->payload_path));
        $this->assertSame($workingFilesBefore, $this->workingFiles($this->document));

        $this->runJob($export);
        $this->assertSame(PdfExport::STATUS_COMPLETED, $export->refresh()->status);
    }

    public function test_in_request_export_is_refused_where_sync_is_switched_off(): void
    {
        $sync = $this->withSession($this->session)
            ->post(route('documents.downloadAnnotatedPdf', $this->document), $this->payload(), ['Accept' => 'application/pdf, application/json']);
        $sync->assertOk();
        $this->assertStringStartsWith('%PDF', file_get_contents($sync->baseResponse->getFile()->getPathname()));

        config(['pdf_export.allow_sync' => false]);
        Queue::fake();

        $this->withSession($this->session)
            ->postJson(route('documents.downloadAnnotatedPdf', $this->document), $this->payload())
            ->assertStatus(409)
            ->assertJson(['success' => false]);
        $this->withSession($this->session)
            ->postJson(route('documents.downloadAnnotatedPdf', $this->document), $this->payload(), self::QUEUED)
            ->assertStatus(202);
    }

    public function test_cleanup_removes_expired_exports_stalled_rows_and_leaked_working_files(): void
    {
        $finished = $this->queueExport($this->document);
        $this->runJob($finished);
        $stalled = $this->queueExport($this->document, null, ['session_id' => 'stalled']);

        $leaked = storage_path('app/temp/download_annotated_'.$this->document->id.'_'.(string) \Illuminate\Support\Str::uuid().'.pdf');
        $fresh = storage_path('app/temp/download_annotated_'.$this->document->id.'_'.(string) \Illuminate\Support\Str::uuid().'.pdf');
        File::ensureDirectoryExists(dirname($leaked));
        file_put_contents($leaked, 'leaked');
        file_put_contents($fresh, 'in use');
        touch($leaked, time() - 7 * 3600);

        try {
            $this->artisan('documents:cleanup-temp')->assertExitCode(0);
            $this->assertTrue(Storage::exists($finished->refresh()->output_path), 'a live export is kept');
            $this->assertFileDoesNotExist($leaked);
            $this->assertFileExists($fresh);

            $this->travel(45)->minutes();
            $this->artisan('documents:cleanup-temp')->assertExitCode(0);

            $this->assertFalse(Storage::exists($finished->directory()));
            $this->assertSame(PdfExport::STATUS_FAILED, $stalled->refresh()->status);
            $this->assertFalse(Storage::exists($stalled->directory()));
        } finally {
            @unlink($leaked);
            @unlink($fresh);
        }
    }

    private function makeDocument(string $contents): Document
    {
        $path = 'documents/'.bin2hex(random_bytes(6)).'.pdf';
        Storage::put($path, $contents);

        return Document::query()->create([
            'original_name' => 'invoice.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'annotations' => [],
            'session_annotations' => [],
            'acro_form_entries' => [],
            'use_exact_download_path' => true,
            'use_pdfjs_visible_export' => true,
            'use_conversion_safe_export' => true,
        ], $overrides);
    }

    private function queueExport(Document $document, ?array $session = null, array $payload = []): PdfExport
    {
        Queue::fake();
        $response = $this->withSession($session ?? ['pdf_editor_accessible_document_ids' => [$document->id]])
            ->postJson(route('documents.downloadAnnotatedPdf', $document), $this->payload($payload), self::QUEUED)
            ->assertStatus(202);

        return PdfExport::query()->where('uuid', $response->json('export_id'))->firstOrFail();
    }

    private function runJob(PdfExport $export): void
    {
        (new ExportAnnotatedPdfJob($export->id))->handle(app(DocumentController::class));
    }

    /** Per-request working files of this document in the real storage/app/temp. */
    private function workingFiles(Document $document): array
    {
        $files = array_merge(
            glob(storage_path('app/temp/download_annotated_'.$document->id.'_*')) ?: [],
            glob(storage_path('app/temp/download_ann_'.$document->id.'_*')) ?: [],
        );
        sort($files);

        return $files;
    }

    private function assertLeaksNothing(string $body): void
    {
        $this->assertStringNotContainsString('Traceback', $body);
        $this->assertStringNotContainsString(storage_path(), $body);
        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString('.py', $body);
    }
}
