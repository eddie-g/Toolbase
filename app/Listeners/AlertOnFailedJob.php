<?php

namespace App\Listeners;

use App\Support\SecretRedactor;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Horizon says nothing when a job fails for good. This logs it and mails the
 * operator (config horizon.alert_email) once per job class every ten minutes:
 * enough to know, not enough to flood an inbox while a provider is down.
 * Never the payload: it can hold user content.
 */
class AlertOnFailedJob
{
    public function handle(JobFailed $event): void
    {
        $job = $event->job->resolveName();
        $queue = (string) $event->job->getQueue();
        $attempts = $event->job->attempts();
        $error = Str::limit(SecretRedactor::redact($event->exception->getMessage()), 500);

        Log::error('Queued job failed', [
            'job' => $job,
            'queue' => $queue,
            'connection' => $event->connectionName,
            'attempts' => $attempts,
            'exception' => $error,
        ]);

        $alertEmail = (string) config('horizon.alert_email');
        if ($alertEmail === '' || ! Cache::add('job-failed-alert:'.sha1($job), true, now()->addMinutes(10))) {
            return;
        }

        try {
            Mail::raw(
                'A queued job failed for good on '.gethostname().".\n\nJob: {$job}\nQueue: {$queue}\nAttempts: {$attempts}\nError: {$error}"
                    ."\n\nMore failures of this job in the next ten minutes are not mailed. See Horizon > Failed jobs.",
                fn ($message) => $message->to($alertEmail)->subject('['.config('app.name').'] Queued job failed: '.class_basename($job))
            );
        } catch (\Throwable $mailError) {
            Log::warning('Could not send the failed-job alert', ['error' => $mailError->getMessage()]);
        }
    }
}
