<?php

namespace App\Jobs;

use App\Exceptions\InsufficientCreditBalanceException;
use App\Models\Admin;
use App\Models\DocumentConversion;
use App\Models\User;
use App\Models\UserActivity;
use App\Services\DocumentExportConversionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ConvertDocumentExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public int $conversionId)
    {
        $this->onConnection('redis');
        $this->onQueue('document-conversion');
    }

    public function handle(DocumentExportConversionService $service): void
    {
        $claimed = DocumentConversion::query()
            ->whereKey($this->conversionId)
            ->where('status', DocumentConversion::STATUS_QUEUED)
            ->update([
                'status' => DocumentConversion::STATUS_PROCESSING,
                'progress' => 20,
                'started_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $conversion = DocumentConversion::query()->with('document')->findOrFail($this->conversionId);
        $inputPath = Storage::path($conversion->input_path);
        $outputPath = Storage::path((string) $conversion->output_path);

        try {
            if (! is_file($inputPath)) {
                throw new \RuntimeException('The queued PDF input file is missing.');
            }

            $conversion->forceFill(['progress' => 45])->save();
            $result = $service->convert($conversion, $inputPath, $outputPath);
            $conversion->forceFill(['progress' => 85])->save();

            $actor = $conversion->user_id
                ? User::find($conversion->user_id)
                : Admin::find($conversion->admin_id);
            if (! $actor instanceof User && ! $actor instanceof Admin) {
                throw new \RuntimeException('The account that requested this conversion no longer exists.');
            }

            $billing = $service->charge(
                $actor,
                $conversion->quote ?? [],
                $conversion->document,
                $conversion->format,
                (string) ($result['provider'] ?? 'local'),
                $conversion->uuid,
            );
            $quote = $conversion->quote ?? [];
            $result = array_merge($result, [
                'charge_usd' => (float) ($quote['charge_usd'] ?? 0),
                'credit_balance' => $billing['credit_balance'],
                'page_count' => (int) ($quote['page_count'] ?? 0),
                'billable_transactions' => (int) ($quote['transactions'] ?? 0),
                'billing_transaction_id' => $billing['transaction_id'],
            ]);

            $conversion->forceFill([
                'status' => DocumentConversion::STATUS_COMPLETED,
                'progress' => 100,
                'result' => $result,
                'error' => null,
                'completed_at' => now(),
                'expires_at' => now()->addMinutes(10),
            ])->save();

            $this->recordActivity($conversion, 'success', $result);
        } catch (\Throwable $exception) {
            @unlink($outputPath);
            $this->markFailed($conversion, $this->publicErrorMessage($exception));
            $this->recordActivity($conversion, 'failed', ['error' => $exception->getMessage()]);
            throw $exception;
        } finally {
            @unlink($inputPath);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $conversion = DocumentConversion::find($this->conversionId);
        if (! $conversion || $conversion->status === DocumentConversion::STATUS_COMPLETED) {
            return;
        }

        if ($conversion->output_path) {
            @unlink(Storage::path($conversion->output_path));
        }
        if ($conversion->input_path) {
            @unlink(Storage::path($conversion->input_path));
        }
        $this->markFailed(
            $conversion,
            $exception ? $this->publicErrorMessage($exception) : 'The conversion worker stopped unexpectedly.'
        );
    }

    public function tags(): array
    {
        $conversion = DocumentConversion::find($this->conversionId);

        return array_values(array_filter([
            'document-conversion',
            $conversion ? "document:{$conversion->document_id}" : null,
            $conversion ? "format:{$conversion->format}" : null,
        ]));
    }

    private function markFailed(DocumentConversion $conversion, string $message): void
    {
        $conversion->forceFill([
            'status' => DocumentConversion::STATUS_FAILED,
            'progress' => 100,
            'error' => $message,
            'completed_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ])->save();

        Log::error('Queued document conversion failed.', [
            'conversion_id' => $conversion->uuid,
            'document_id' => $conversion->document_id,
            'format' => $conversion->format,
            'error' => $message,
        ]);
    }

    private function publicErrorMessage(\Throwable $exception): string
    {
        if ($exception instanceof InsufficientCreditBalanceException) {
            return sprintf(
                'Your balance changed while the conversion was queued. Required $%.4f; available $%.4f.',
                $exception->required,
                $exception->available,
            );
        }

        $message = trim($exception->getMessage());

        return $message !== '' ? $message : 'The document conversion failed.';
    }

    private function recordActivity(DocumentConversion $conversion, string $status, array $details): void
    {
        if (! $conversion->user_id) {
            return;
        }

        UserActivity::create([
            'user_id' => $conversion->user_id,
            'action' => $conversion->format === 'word' ? 'Convert to Word' : 'Convert to Excel',
            'category' => $conversion->format === 'word' ? 'word_export' : 'excel_export',
            'details' => array_merge($conversion->options ?? [], $details, [
                'queued' => true,
                'conversion_id' => $conversion->uuid,
            ]),
            'document_id' => $conversion->document_id,
            'status' => $status,
            'ip_address' => $conversion->ip_address,
            'user_agent' => $conversion->user_agent,
        ]);
    }
}
