<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\CreditTransaction;
use App\Models\Document;
use App\Models\DocumentConversion;
use App\Models\DocumentConversionSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class DocumentExportConversionService
{
    public function __construct(
        private readonly DocumentConversionPricing $pricing,
        private readonly AdobePdfServices $adobe,
    ) {}

    public function quote(string $inputPath): array
    {
        $pythonBinary = $this->resolvePythonBinary(['fitz']);
        $output = [];
        $exitCode = 1;
        exec(sprintf(
            '%s -c %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg('import fitz,sys; doc=fitz.open(sys.argv[1]); print(doc.page_count); doc.close()'),
            escapeshellarg($inputPath)
        ), $output, $exitCode);

        $pageCount = (int) trim((string) end($output));
        if ($exitCode !== 0 || $pageCount < 1) {
            throw new \RuntimeException('The PDF page count could not be determined.');
        }

        return $this->pricing->quote($pageCount);
    }

    public function convert(DocumentConversion $conversion, string $inputPath, string $outputPath): array
    {
        $format = strtolower((string) $conversion->format);
        if (! in_array($format, ['word', 'excel'], true)) {
            throw new \InvalidArgumentException("Unsupported document conversion format [{$format}].");
        }

        $directory = dirname($outputPath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create the conversion output directory.');
        }

        $settings = DocumentConversionSetting::current();
        $requestedProvider = $format === 'word'
            ? $settings->word_provider
            : $settings->excel_provider;
        $providerUsed = $requestedProvider;
        $providerFallbackUsed = false;
        $providerError = null;
        $visualFidelity = $format === 'word' && (bool) (($conversion->options ?? [])['visual_fidelity'] ?? false);
        $preparedInputPath = null;
        $preparationResult = null;

        try {
            if ($visualFidelity) {
                // Re-author the visible pages into a fresh untagged PDF so
                // Adobe uses the edited content instead of stale source tags.
                // This preserves native text, vector shapes, and image layers.
                $requestedProvider = DocumentConversionSetting::PROVIDER_ADOBE;
                $providerUsed = DocumentConversionSetting::PROVIDER_ADOBE;
                $preparedInputPath = $directory.'/adobe-input-prepared.pdf';
                $preparationResult = $this->preparePdfForAdobe($inputPath, $preparedInputPath);
                $result = $this->adobe->export($preparedInputPath, $outputPath, 'docx');
            } elseif ($requestedProvider === DocumentConversionSetting::PROVIDER_ADOBE) {
                try {
                    $result = $this->adobe->export(
                        $inputPath,
                        $outputPath,
                        $format === 'word' ? 'docx' : 'xlsx'
                    );
                } catch (\Throwable $exception) {
                    if (! $settings->fallback_to_local) {
                        throw $exception;
                    }

                    $providerError = $exception->getMessage();
                    $providerUsed = DocumentConversionSetting::PROVIDER_LOCAL;
                    $providerFallbackUsed = true;
                    @unlink($outputPath);
                    Log::warning("Adobe {$format} export failed; using local converter.", [
                        'document_id' => $conversion->document_id,
                        'conversion_id' => $conversion->uuid,
                        'error' => $providerError,
                    ]);
                    $result = $this->runLocalConversion($format, $inputPath, $outputPath, $conversion->options ?? []);
                }
            } else {
                $providerUsed = DocumentConversionSetting::PROVIDER_LOCAL;
                $result = $this->runLocalConversion($format, $inputPath, $outputPath, $conversion->options ?? []);
            }
        } catch (\Throwable $exception) {
            @unlink($outputPath);
            throw $exception;
        } finally {
            if ($preparedInputPath !== null) {
                @unlink($preparedInputPath);
            }
        }

        if (! is_file($outputPath) || filesize($outputPath) < 1) {
            throw new \RuntimeException(ucfirst($format).' output file was not created.');
        }

        return array_merge($result, $preparationResult === null ? [] : [
            'prepared_for_adobe' => true,
            'prepared_pages' => $preparationResult['pages'] ?? null,
            'native_text_preserved' => (bool) ($preparationResult['native_text_preserved'] ?? false),
            'vector_shapes_preserved' => (bool) ($preparationResult['vector_shapes_preserved'] ?? false),
            'image_layers_preserved' => (bool) ($preparationResult['image_layers_preserved'] ?? false),
        ], [
            'provider_requested' => $requestedProvider,
            'provider' => $providerUsed,
            'provider_fallback_used' => $providerFallbackUsed,
            'provider_bypassed_for_visual_fidelity' => false,
            'provider_error' => $providerError,
            'file_size' => filesize($outputPath),
        ]);
    }

    public function charge(
        User|Admin $actor,
        array $quote,
        Document $document,
        string $format,
        string $provider,
        string $conversionUuid,
    ): array {
        $amount = (float) ($quote['charge_usd'] ?? 0);
        if ($amount <= 0) {
            return ['credit_balance' => (float) $actor->credit_balance, 'transaction_id' => null];
        }

        $metadata = [
            'document_id' => $document->id,
            'document_conversion_uuid' => $conversionUuid,
            'format' => $format,
            'provider' => $provider,
            'page_count' => $quote['page_count'],
            'pages_per_transaction' => $quote['pages_per_transaction'],
            'billable_transactions' => $quote['transactions'],
            'price_per_transaction' => $quote['price_per_transaction'],
        ];
        $description = sprintf(
            '%s PDF conversion (%d page%s)',
            ucfirst($format),
            $quote['page_count'],
            $quote['page_count'] === 1 ? '' : 's'
        );

        if ($actor instanceof User) {
            $transaction = CreditTransaction::debitIfSufficient(
                userId: $actor->id,
                amount: $amount,
                service: 'document_conversion',
                modelName: $provider === DocumentConversionSetting::PROVIDER_ADOBE
                    ? 'adobe-pdf-services-export'
                    : 'toolbase-local-converter',
                description: $description,
                metadata: $metadata,
            );

            return [
                'credit_balance' => (float) $transaction->balance_after,
                'transaction_id' => $transaction->id,
            ];
        }

        $actor->debitBalanceIfSufficient($amount);

        return ['credit_balance' => (float) $actor->credit_balance, 'transaction_id' => null];
    }

    private function runLocalConversion(
        string $format,
        string $inputPath,
        string $outputPath,
        array $options,
    ): array {
        return $format === 'word'
            ? $this->runLocalWordConversion($inputPath, $outputPath, $options)
            : $this->runLocalExcelConversion($inputPath, $outputPath, $options);
    }

    private function preparePdfForAdobe(string $inputPath, string $outputPath): array
    {
        @unlink($outputPath);
        $command = sprintf(
            '%s %s %s %s --json 2>&1',
            escapeshellarg($this->resolvePythonBinary(['fitz'])),
            escapeshellarg(base_path('python/pdf-editor/prepare_pdf_for_adobe.py')),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
        );

        try {
            $result = $this->parseLocalConversionResult(
                shell_exec($command),
                'Adobe input preparation'
            );
        } catch (\Throwable $exception) {
            @unlink($outputPath);
            throw $exception;
        }

        if (! is_file($outputPath) || filesize($outputPath) < 1) {
            throw new \RuntimeException('The prepared Adobe input PDF was not created.');
        }

        return $result;
    }

    private function runLocalWordConversion(string $inputPath, string $outputPath, array $options): array
    {
        $layout = in_array(($options['layout'] ?? null), ['flow', 'exact'], true)
            ? $options['layout']
            : 'exact';
        $includeImages = (bool) ($options['include_images'] ?? true);
        $ocr = (bool) ($options['ocr'] ?? false);
        $visualFidelity = (bool) ($options['visual_fidelity'] ?? false);
        $requiredModules = $layout === 'exact' && $includeImages && ! $ocr && ! $visualFidelity
            ? ['fitz', 'docx', 'pdf2docx']
            : ['fitz', 'docx'];
        $command = sprintf(
            '%s %s %s %s --layout %s %s %s %s --json 2>&1',
            escapeshellarg($this->resolvePythonBinary($requiredModules)),
            escapeshellarg(base_path('python/pdf-editor/convert_to_word.py')),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($layout),
            $includeImages ? '--images' : '--no-images',
            $ocr ? '--ocr' : '',
            $visualFidelity ? '--visual-fidelity' : ''
        );

        return $this->parseLocalConversionResult(shell_exec($command), 'Word');
    }

    private function runLocalExcelConversion(string $inputPath, string $outputPath, array $options): array
    {
        $mode = in_array(($options['mode'] ?? null), ['tables', 'all'], true)
            ? $options['mode']
            : 'all';
        $mergeCells = (bool) ($options['merge_cells'] ?? true);
        $sheetPerPage = (bool) ($options['sheet_per_page'] ?? true);
        $command = sprintf(
            '%s %s %s %s --mode %s %s %s --json 2>&1',
            escapeshellarg($this->resolvePythonBinary(['fitz', 'openpyxl'])),
            escapeshellarg(base_path('python/pdf-editor/convert_to_excel.py')),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($mode),
            $mergeCells ? '--merge-cells' : '--no-merge-cells',
            $sheetPerPage ? '--sheet-per-page' : '--single-sheet'
        );

        return $this->parseLocalConversionResult(shell_exec($command), 'Excel');
    }

    private function parseLocalConversionResult(?string $output, string $format): array
    {
        $result = null;
        foreach (explode("\n", trim((string) $output)) as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                $result = $decoded;
            }
        }

        if (! $result || ! ($result['success'] ?? false)) {
            throw new \RuntimeException(
                $result['error'] ?? "{$format} conversion failed. Output: ".($output ?: 'none')
            );
        }

        return $result;
    }

    private function resolvePythonBinary(array $requiredModules): string
    {
        static $resolved = [];
        $requiredModules = array_values(array_filter(
            $requiredModules,
            static fn ($module) => is_string($module) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $module)
        ));
        $cacheKey = implode('|', $requiredModules);
        if (isset($resolved[$cacheKey])) {
            return $resolved[$cacheKey];
        }

        $candidates = array_values(array_unique([
            base_path('.venv/bin/python'),
            base_path('venv/bin/python'),
            base_path('python/venv/bin/python'),
            '/usr/bin/python3',
            'python3',
        ]));

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '/') && ! is_executable($candidate)) {
                continue;
            }
            $output = [];
            $exitCode = 1;
            exec(sprintf(
                '%s -c %s 2>&1',
                escapeshellarg($candidate),
                escapeshellarg(implode('; ', array_map(static fn ($module) => "import {$module}", $requiredModules)))
            ), $output, $exitCode);
            if ($exitCode === 0) {
                return $resolved[$cacheKey] = $candidate;
            }
        }

        return $resolved[$cacheKey] = 'python3';
    }
}
