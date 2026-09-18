<?php

namespace Tests\Feature;

use App\Exceptions\PythonServiceBusyException;
use App\Http\Controllers\DocumentController;
use App\Jobs\ProcessUploadedDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The upload pipeline: ProcessUploadedDocumentJob on its own queue, one per
 * document, every outcome written to documents.processing_status, and the
 * status and retry endpoints the editor waits on.
 */
class UploadProcessingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->document = $this->makeDocument();
    }

    public function test_the_job_runs_on_its_own_queue_once_per_document(): void
    {
        Queue::fake();
        $processing = app(DocumentProcessing::class);

        $this->assertTrue($processing->queue($this->document, 'someone@example.com', 'session-1'));
        $processing->queue($this->document, 'someone@example.com', 'session-1');   // a refresh, a double submit
        $other = $this->makeDocument();
        $processing->queue($other);

        Queue::assertPushed(ProcessUploadedDocumentJob::class, 2);
        Queue::assertPushed(ProcessUploadedDocumentJob::class, function (ProcessUploadedDocumentJob $job) {
            $overlap = collect($job->middleware())->first(fn ($middleware) => $middleware instanceof WithoutOverlapping);

            return $job->documentId === $this->document->id
                && $job->queue === 'pdf-extraction'
                && $job->timeout === 300
                && $job->tries === 2
                && $job->failOnTimeout
                && $job->uniqueId() === (string) $this->document->id
                && $overlap !== null
                && $overlap->key === (string) $this->document->id;
        });

        $status = $processing->status($this->document);
        $this->assertSame(['queued', true, false], [$status['status'], $status['pending'], $status['can_retry']]);
        $this->assertNotNull($this->row()->processing_queued_at);
    }

    public function test_success_marks_the_document_ready(): void
    {
        $this->controllerReturns(['success' => true]);
        $updatedAt = $this->document->updated_at->toDateTimeString();

        $this->runJob();

        $row = $this->row();
        $this->assertSame('ready', $row->processing_status);
        $this->assertNull($row->processing_error);
        $this->assertSame(1, (int) $row->processing_attempts);
        $this->assertNotNull($row->processing_started_at);
        $this->assertNotNull($row->processing_finished_at);
        $this->assertSame($updatedAt, $this->document->fresh()->updated_at->toDateTimeString(), 'the documents list order is not disturbed');

        $this->actingAs($this->user)
            ->getJson(route('documents.processing.status', $this->document))
            ->assertOk()
            ->assertJson(['success' => true, 'status' => 'ready', 'pending' => false, 'can_retry' => false, 'message' => null, 'retry_url' => null]);
    }

    public function test_a_failed_extraction_marks_the_document_and_tells_the_editor(): void
    {
        $this->controllerReturns(['success' => false, 'code' => 'extraction_failed']);

        $this->runJob();

        $this->assertSame(['failed', 'extraction_failed'], [$this->row()->processing_status, $this->row()->processing_error]);

        $this->actingAs($this->user)
            ->getJson(route('documents.processing.status', $this->document))
            ->assertOk()
            ->assertJson([
                'status' => 'failed',
                'pending' => false,
                'can_retry' => true,
                'error_code' => 'extraction_failed',
                'message' => 'The text of this PDF could not be read.',
                'retry_url' => route('documents.processing.retry', $this->document),
            ]);

        // The state request the editor opens with says the same, and does not say "pending".
        $info = $this->actingAs($this->user)
            ->getJson(route('pdfTests.documentInfo', $this->document).'?session_id=tab&skip_embedded_fonts=1');
        $info->assertOk()->assertJson([
            'extraction_pending' => false,
            'processing' => ['status' => 'failed', 'error_code' => 'extraction_failed', 'can_retry' => true],
        ]);
    }

    public function test_a_timeout_a_crash_and_a_busy_pool_each_leave_a_status(): void
    {
        // The whole job ran past its budget: Horizon fails it and calls failed().
        (new ProcessUploadedDocumentJob($this->document->id))->failed(new TimeoutExceededException('timed out'));
        $this->assertSame(['failed', 'timeout'], [$this->row()->processing_status, $this->row()->processing_error]);

        (new ProcessUploadedDocumentJob($this->document->id))->failed(new \RuntimeException('boom'));
        $this->assertSame(['failed', 'worker_failed'], [$this->row()->processing_status, $this->row()->processing_error]);

        // No Python slot: not a failure of the PDF, so it goes back on the queue.
        $this->mock(DocumentController::class)
            ->shouldReceive('processUploadedDocument')->once()->andThrow(new PythonServiceBusyException());
        $this->runJob();
        $this->assertSame('queued', $this->row()->processing_status);
        $this->assertNull($this->row()->processing_error);
    }

    public function test_a_hung_extractor_is_reported_as_a_timeout_within_seconds(): void
    {
        $diskRoot = sys_get_temp_dir().'/netkit_upload_processing_'.bin2hex(random_bytes(6));
        File::makeDirectory($diskRoot, 0700, true);
        // Stands in for the interpreter: passes the module probe, then hangs.
        $hangingPython = $diskRoot.'/hanging-python';
        file_put_contents($hangingPython, "#!/bin/sh\n[ \"$1\" = \"-c\" ] && exit 0\nexec sleep 60\n");
        chmod($hangingPython, 0755);
        config([
            'filesystems.disks.local.root' => $diskRoot,
            'python.binary' => $hangingPython,
            'python.timeouts' => ['default' => 1],
        ]);
        Storage::forgetDisk('local');
        Storage::put($this->document->path, file_get_contents(base_path('tests/OverlayEditor/invoicesample.pdf')));

        try {
            $started = microtime(true);
            $this->runJob();

            $this->assertLessThan(20, microtime(true) - $started, 'the process timeouts end it, not the sleep');
            $this->assertSame(['failed', 'extraction_timeout'], [$this->row()->processing_status, $this->row()->processing_error]);
            $this->assertSame(
                'This PDF took too long to process.',
                app(DocumentProcessing::class)->status($this->document)['message']
            );
        } finally {
            Storage::forgetDisk('local');
            File::deleteDirectory($diskRoot);
        }
    }

    public function test_a_lost_job_is_reported_as_failed_and_can_be_retried_once(): void
    {
        Queue::fake();
        $processing = app(DocumentProcessing::class);

        // Queued eleven minutes ago and never picked up.
        DB::table('documents')->where('id', $this->document->id)->update([
            'processing_status' => 'queued',
            'processing_queued_at' => now()->subMinutes(11),
        ]);
        $lost = $processing->status($this->document);
        $this->assertSame(['failed', 'never_started', true], [$lost['status'], $lost['error_code'], $lost['can_retry']]);
        $this->assertSame('Processing did not start. The service may be busy.', $lost['message']);

        // Extracting for longer than the job is allowed to live.
        DB::table('documents')->where('id', $this->document->id)->update([
            'processing_status' => 'extracting',
            'processing_started_at' => now()->subMinutes(7),
        ]);
        $this->assertSame('stalled', $processing->status($this->document)['error_code']);

        // ...while a run that is still inside its budget is simply pending.
        DB::table('documents')->where('id', $this->document->id)->update(['processing_started_at' => now()->subMinutes(2)]);
        $this->assertTrue($processing->status($this->document)['pending']);

        // Retry: refused while it is pending, accepted once it has failed, and queues one job however often it is clicked.
        $this->actingAs($this->user)->postJson(route('documents.processing.retry', $this->document))
            ->assertOk()->assertJson(['status' => 'extracting', 'pending' => true]);
        Queue::assertNothingPushed();

        $processing->markFailed($this->document->id, 'extraction_failed');
        $this->actingAs($this->user)->postJson(route('documents.processing.retry', $this->document))
            ->assertStatus(202)->assertJson(['status' => 'queued', 'pending' => true, 'retry_url' => null]);
        $this->actingAs($this->user)->postJson(route('documents.processing.retry', $this->document))->assertOk();
        Queue::assertPushed(ProcessUploadedDocumentJob::class, 1);

        // Someone else's document is not theirs to poll or retry.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson(route('documents.processing.status', $this->document))->assertNotFound();
        $this->actingAs($stranger)->postJson(route('documents.processing.retry', $this->document))->assertNotFound();
    }

    public function test_documents_from_before_the_status_column_are_ready(): void
    {
        $this->assertNull($this->row()->processing_status);

        $status = app(DocumentProcessing::class)->status($this->document);
        $this->assertSame(['ready', false, false], [$status['status'], $status['pending'], $status['can_retry']]);

        $this->actingAs($this->user)
            ->getJson(route('pdfTests.documentInfo', $this->document).'?session_id=tab&skip_embedded_fonts=1')
            ->assertOk()
            ->assertJson(['extraction_pending' => false, 'processing' => ['status' => 'ready', 'can_retry' => false]]);
    }

    private function makeDocument(): Document
    {
        return Document::query()->create([
            'user_id' => $this->user->id,
            'original_name' => 'upload.pdf',
            'path' => 'documents/upload_'.bin2hex(random_bytes(4)).'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);
    }

    private function controllerReturns(array $result): void
    {
        $this->mock(DocumentController::class)
            ->shouldReceive('processUploadedDocument')
            ->once()
            ->with($this->document->id, null, null)
            ->andReturn($result);
    }

    private function runJob(): void
    {
        try {
            app()->call([new ProcessUploadedDocumentJob($this->document->id), 'handle']);
        } finally {
            // A mocked controller is for the job only; the requests that follow get the real one.
            app()->forgetInstance(DocumentController::class);
        }
    }

    private function row(): object
    {
        return DB::table('documents')->where('id', $this->document->id)->first();
    }
}
