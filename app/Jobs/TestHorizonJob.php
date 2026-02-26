<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TestHorizonJob implements ShouldQueue
{
    use Queueable;

    public string $message;

    /**
     * Create a new job instance.
     */
    public function __construct(string $message = 'Hello from Horizon!')
    {
        $this->message = $message;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('[TestHorizonJob] Processed on queue: ' . ($this->queue ?? 'default') . ' | Message: ' . $this->message);
    }
}
