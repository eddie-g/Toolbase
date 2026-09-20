<?php

namespace Tests\Feature;

use App\Jobs\ConvertDocumentExportJob;
use App\Listeners\AlertOnFailedJob;
use App\Models\Document;
use App\Models\DocumentConversion;
use App\Models\User;
use App\Services\DocumentExportConversionService;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Queues: every job runs on a queue that has workers and a wait alarm, long
 * jobs stay off the queue mail uses, a transient conversion failure gets a
 * second attempt, and a job that fails for good is reported.
 */
class HorizonTuningTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_job_runs_on_a_supervised_queue_with_a_wait_alarm_and_long_jobs_stay_off_default(): void
    {
        $supervised = collect(config('horizon.defaults'))->flatMap(fn (array $supervisor) => $supervisor['queue'])->unique()->values()->all();
        $alarmed = array_map(fn (string $key) => Str::after($key, ':'), array_keys(config('horizon.waits')));
        $this->assertEqualsCanonicalizing($supervised, $alarmed, 'one wait alarm per supervised queue');

        foreach (Finder::create()->files()->in(app_path('Jobs'))->name('*.php') as $file) {
            $source = $file->getContents();
            if (! preg_match('/onQueue\((.+?)\);/', $source, $match)) {
                $queue = 'default';
            } elseif (preg_match("/^'([^']+)'$/", trim($match[1]), $literal)) {
                $queue = $literal[1];
            } else {
                preg_match("/,\s*'([^']+)'\)$/", trim($match[1]), $fallback);   // config('...', 'pdf-export')
                $queue = $fallback[1] ?? 'default';
            }
            $this->assertContains($queue, $supervised, $file->getFilename().' is queued where no worker listens');

            preg_match('/public int \$timeout\s*=\s*(\d+)/', $source, $timeout);
            if ($queue === 'default') {
                $this->assertLessThanOrEqual(60, (int) ($timeout[1] ?? 0), $file->getFilename().' would hold a worker that mail is waiting for');
            }
        }

        $this->assertGreaterThanOrEqual(1024, config('horizon.defaults.supervisor-document-conversions.memory'));
        $this->assertSame(2, config('horizon.defaults.supervisor-document-conversions.tries'));
        $this->assertGreaterThanOrEqual(256, config('horizon.memory_limit'));
    }

    public function test_a_failed_conversion_gets_one_more_attempt_before_the_user_is_told(): void
    {
        $diskRoot = sys_get_temp_dir().'/netkit_horizon_tuning_'.bin2hex(random_bytes(6));
        File::makeDirectory($diskRoot, 0700, true);
        config(['filesystems.disks.local.root' => $diskRoot]);
        Storage::forgetDisk('local');

        try {
            $user = User::factory()->create(['credit_balance' => 10]);
            $document = Document::create(['user_id' => $user->id, 'original_name' => 'a.pdf', 'path' => 'documents/a.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1]);
            Storage::put('documents/conversions/x/input.pdf', '%PDF-1.4');
            $conversion = DocumentConversion::create([
                'uuid' => (string) Str::uuid(), 'document_id' => $document->id, 'user_id' => $user->id, 'format' => 'word',
                'status' => DocumentConversion::STATUS_QUEUED, 'progress' => 5, 'options' => [], 'quote' => ['charge_usd' => 0.1, 'page_count' => 1, 'transactions' => 1],
                'input_path' => 'documents/conversions/x/input.pdf', 'output_path' => 'documents/conversions/x/output.docx', 'download_name' => 'a.docx', 'queued_at' => now(),
            ]);

            $service = Mockery::mock(DocumentExportConversionService::class);
            $service->shouldReceive('convert')->twice()->andThrow(new \RuntimeException('Adobe: 503 Service Unavailable'));
            $service->shouldNotReceive('charge');

            // First attempt: not final. Back to queued, the input kept for the retry.
            $first = $this->jobOnAttempt($conversion->id, 1);
            $first->handle($service);
            $conversion->refresh();
            $this->assertSame(DocumentConversion::STATUS_QUEUED, $conversion->status);
            $this->assertNull($conversion->error);
            $this->assertTrue(Storage::exists($conversion->input_path), 'the second attempt needs the input');

            // Second attempt fails too: now it is final, and nothing was charged.
            $second = $this->jobOnAttempt($conversion->id, 2);
            try {
                $second->handle($service);
                $this->fail('the last attempt lets the failure through');
            } catch (\RuntimeException) {
            }
            $conversion->refresh();
            $this->assertSame(DocumentConversion::STATUS_FAILED, $conversion->status);
            $this->assertStringContainsString('503', (string) $conversion->error);
            $this->assertFalse(Storage::exists($conversion->input_path));
            $this->assertSame('10.0000', (string) $user->fresh()->credit_balance);
            $this->assertSame(2, $second->tries);
        } finally {
            Storage::forgetDisk('local');
            File::deleteDirectory($diskRoot);
        }
    }

    public function test_a_job_that_fails_for_good_is_mailed_to_the_operator_once_per_ten_minutes(): void
    {
        config(['horizon.alert_email' => 'ops@example.test']);

        $job = Mockery::mock(QueueJob::class);
        $job->shouldReceive('resolveName')->andReturn(ConvertDocumentExportJob::class);
        $job->shouldReceive('getQueue')->andReturn('document-conversion');
        $job->shouldReceive('attempts')->andReturn(2);
        $job->shouldReceive('payload')->never();
        $fail = fn (string $error) => app(AlertOnFailedJob::class)->handle(new JobFailed('redis', $job, new \RuntimeException($error)));

        $fail('Adobe: 503 Service Unavailable');
        $fail('Adobe: 503 Service Unavailable');

        $messages = collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->filter(fn ($email) => $email->getTo()[0]->getAddress() === 'ops@example.test');
        $this->assertCount(1, $messages, 'the second failure of the same job within ten minutes is not mailed');
        $email = $messages->first();
        $this->assertStringContainsString('ConvertDocumentExportJob', $email->getSubject());
        $this->assertStringContainsString('document-conversion', $email->getTextBody());
        $this->assertStringContainsString('503', $email->getTextBody());

        $this->travel(11)->minutes();
        $fail('again');
        $this->assertGreaterThanOrEqual(2, collect(app('mailer')->getSymfonyTransport()->messages())->count());
    }

    private function jobOnAttempt(int $conversionId, int $attempt): ConvertDocumentExportJob
    {
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $queueJob->shouldReceive('release')->zeroOrMoreTimes();
        $queueJob->shouldReceive('isReleased', 'isDeleted', 'hasFailed')->andReturn(false);

        return (new ConvertDocumentExportJob($conversionId))->setJob($queueJob);
    }
}
