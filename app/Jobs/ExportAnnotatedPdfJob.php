<?php

namespace App\Jobs;

use App\Exceptions\PythonServiceBusyException;
use App\Http\Controllers\DocumentController;
use App\Models\Admin;
use App\Models\PdfExport;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The editor's Download: stamps the annotations onto a copy of the PDF outside
 * the web request. The row in pdf_exports is what the client polls.
 *
 * A failure of the export itself (bad payload, Python exit code, a process
 * timeout) is final and is not retried: the same input fails the same way. Only
 * a worker that could not get a Python slot, or an unexpected crash, retries.
 */
class ExportAnnotatedPdfJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public bool $failOnTimeout = true;

    public int $timeout;

    public int $uniqueFor = 600;

    public function __construct(public int $exportId)
    {
        $this->timeout = max(30, (int) config('pdf_export.job_timeout', 240));
        $this->onConnection(config('pdf_export.connection', 'redis'));
        $this->onQueue(config('pdf_export.queue', 'pdf-export'));
    }

    public function uniqueId(): string
    {
        return (string) $this->exportId;
    }

    public function backoff(): array
    {
        return [5, 20];
    }

    public function handle(DocumentController $documents): void
    {
        $claimed = PdfExport::query()
            ->whereKey($this->exportId)
            ->whereIn('status', [PdfExport::STATUS_QUEUED, PdfExport::STATUS_PROCESSING])
            ->update([
                'status' => PdfExport::STATUS_PROCESSING,
                'progress' => 15,
                'started_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return;
        }

        $export = PdfExport::query()->with('document')->findOrFail($this->exportId);
        $document = $export->document;
        if (! $document) {
            $this->markFailed($export, 'The document no longer exists.');

            return;
        }

        $input = json_decode((string) Storage::get($export->payload_path), true);
        if (! is_array($input)) {
            $this->markFailed($export, 'The export request could not be read. Download the PDF again.');

            return;
        }

        $this->actAs($export);
        try {
            $result = $documents->generateAnnotatedPdfExport(
                $document,
                $input,
                $export->uuid,
                static fn (int $percent) => $export->setProgress($percent),
            );
        } catch (PythonServiceBusyException $exception) {
            // Every Python slot on this server is taken: go back on the queue
            // rather than fail the user's download.
            if ($this->attempts() < $this->tries) {
                $export->forceFill(['status' => PdfExport::STATUS_QUEUED, 'progress' => 10])->save();
                $this->release(10);

                return;
            }
            $this->markFailed($export, 'The PDF service is busy. Please try again in a moment.');

            return;
        } finally {
            Auth::forgetGuards();
        }

        if (! ($result['success'] ?? false)) {
            $this->markFailed($export, (string) ($result['message'] ?? 'Failed to generate annotated PDF.'));

            return;
        }

        $outputPath = $export->directory().'/output.pdf';
        $tempPath = (string) $result['path'];
        try {
            $stream = fopen($tempPath, 'rb');
            $stored = $stream !== false && Storage::put($outputPath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (! $stored) {
                throw new \RuntimeException('Unable to store the exported PDF.');
            }
        } finally {
            @unlink($tempPath);
        }

        Storage::delete($export->payload_path);
        $export->forceFill([
            'status' => PdfExport::STATUS_COMPLETED,
            'progress' => 100,
            'output_path' => $outputPath,
            'output_bytes' => Storage::size($outputPath),
            'error' => null,
            'completed_at' => now(),
            'expires_at' => now()->addMinutes(max(1, (int) config('pdf_export.result_ttl_minutes', 15))),
        ])->save();
    }

    /** Timeout, worker crash, or the last attempt threw. */
    public function failed(?\Throwable $exception): void
    {
        $export = PdfExport::find($this->exportId);
        if (! $export || $export->status === PdfExport::STATUS_COMPLETED) {
            return;
        }

        // A Python process that overruns is reported by generateAnnotatedPdfExport();
        // this is the whole job running past its budget.
        $timedOut = $exception instanceof TimeoutExceededException;
        $this->markFailed(
            $export,
            $timedOut
                ? 'The PDF took too long to generate. Try again, or remove very large images from the document.'
                : 'Failed to generate annotated PDF.',
            $exception
        );
    }

    public function tags(): array
    {
        $export = PdfExport::find($this->exportId);

        return array_values(array_filter([
            'pdf-export',
            $export ? "document:{$export->document_id}" : null,
        ]));
    }

    /**
     * The export helpers resolve the editor's identity (state ownership, the
     * e-mail passed to the extraction scripts) from the auth guards. A worker
     * has no session, so the requesting account is put on the guards for the
     * length of the job and cleared again in handle().
     */
    private function actAs(PdfExport $export): void
    {
        Auth::forgetGuards();
        if ($export->user_id && ($user = User::find($export->user_id))) {
            Auth::guard('web')->setUser($user);
        }
        if ($export->admin_id && ($admin = Admin::find($export->admin_id))) {
            Auth::guard('admin')->setUser($admin);
        }
    }

    private function markFailed(PdfExport $export, string $message, ?\Throwable $exception = null): void
    {
        $export->deleteFiles();
        $export->forceFill([
            'status' => PdfExport::STATUS_FAILED,
            'progress' => 100,
            'output_path' => null,
            'error' => $message,
            'completed_at' => now(),
            'expires_at' => now()->addMinutes(max(1, (int) config('pdf_export.result_ttl_minutes', 15))),
        ])->save();

        Log::error('Queued annotated PDF export failed.', [
            'reference' => $export->uuid,
            'document_id' => $export->document_id,
            'message' => $message,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
