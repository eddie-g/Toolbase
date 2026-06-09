<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiDomainsJob;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GenerateAiDomainsJobTest extends TestCase
{
    public function test_failed_timeout_marks_cached_ai_domain_job_failed(): void
    {
        $jobId = '083dd568-b61c-4fd9-99cc-698b389a4698';
        Cache::put('ai-domain-job:' . $jobId, [
            'status' => 'processing',
            'done' => false,
            'owner' => 'guest:127.0.0.1:test-session',
            'queued_at' => '2026-06-02T14:59:43.695194Z',
            'updated_at' => '2026-06-02T15:05:46.302911Z',
        ], now()->addMinutes(30));

        $job = new GenerateAiDomainsJob(
            jobId: $jobId,
            prompt: 'a domain name for a fitness tracking application',
            tlds: ['com'],
        );

        $job->failed(new TimeoutExceededException('App\Jobs\GenerateAiDomainsJob has timed out.'));

        $state = Cache::get('ai-domain-job:' . $jobId);

        $this->assertSame('failed', $state['status']);
        $this->assertTrue($state['done']);
        $this->assertSame('guest:127.0.0.1:test-session', $state['owner']);
        $this->assertSame('AI domain generation timed out before completion. Please try again.', $state['error']);
    }
}
