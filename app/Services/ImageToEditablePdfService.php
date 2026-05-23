<?php

namespace App\Services;

use App\Models\Document;
use App\Models\PdfState;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class ImageToEditablePdfService
{
    /**
     * @return array{document: Document, text_count: int, shape_count: int, warnings: array<int, string>}
     */
    public function convert(string $imagePath, string $originalName, array $options = []): array
    {
        if (!is_file($imagePath)) {
            throw new RuntimeException('Uploaded image was not found.');
        }

        Storage::makeDirectory('documents');

        $uuid = Str::uuid()->toString();
        $storedRelative = 'documents/' . $uuid . '.pdf';
        $storedFull = Storage::path($storedRelative);

        $mode = $this->conversionMode((string) ($options['mode'] ?? 'reconstruct'));

        $conversion = $this->runConverter(
            $imagePath,
            $storedFull,
            (string) ($options['page_size'] ?? 'letter'),
            $mode === 'image-backed' ? 0 : (int) ($options['max_shapes'] ?? 260),
            $mode,
        );

        if (!is_file($storedFull)) {
            throw new RuntimeException('The image converter did not create a PDF.');
        }

        $document = null;
        $annotations = [];

        DB::transaction(function () use (&$document, &$annotations, $storedRelative, $storedFull, $originalName, $conversion, $mode): void {
            $document = Document::create([
                ...$this->currentOwnershipPayload(),
                'original_name' => $this->documentName($originalName),
                'path' => $storedRelative,
                'original_backup_path' => $this->createOriginalBackup($storedRelative),
                'mime_type' => 'application/pdf',
                'size_bytes' => filesize($storedFull),
                'mode' => 'editor',
                'form_data' => [
                    'source' => 'image_to_pdf',
                    'source_image_name' => $originalName,
                    'conversion_mode' => $mode,
                    'page' => $conversion['page'] ?? null,
                ],
            ]);

            $annotations = $this->buildAnnotations($document, $conversion);
            $sessionId = 'image_to_pdf_' . $document->id;
            $ownership = $this->pdfStateOwnershipPayload($document, $sessionId);

            foreach ($annotations as $annotation) {
                PdfState::create([
                    'document_id' => $document->id,
                    'page_number' => 0,
                    'annotation_data' => $annotation,
                    'state' => 'saved',
                    ...$ownership,
                ]);
            }
        });

        return [
            'document' => $document,
            'text_count' => count(array_filter($annotations, static fn (array $annotation) => ($annotation['type'] ?? '') === 'text')),
            'shape_count' => count(array_filter($annotations, static fn (array $annotation) => ($annotation['type'] ?? '') === 'shape')),
            'warnings' => array_values(array_filter(array_map('strval', $conversion['warnings'] ?? []))),
        ];
    }

    private function runConverter(string $imagePath, string $outputPdf, string $pageSize, int $maxShapes, string $mode): array
    {
        $script = base_path('python/pdf-editor/image_to_editable_annotations.py');
        if (!is_file($script)) {
            throw new RuntimeException('Image conversion script is missing.');
        }

        $process = new Process([
            $this->resolvePythonBinary('fitz'),
            $script,
            '--image',
            $imagePath,
            '--output-pdf',
            $outputPdf,
            '--page-size',
            $pageSize,
            '--max-shapes',
            (string) max(0, $maxShapes),
            '--mode',
            $mode,
        ]);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error('Image to PDF conversion failed', [
                'exit_code' => $process->getExitCode(),
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
            ]);

            throw new RuntimeException(trim($process->getErrorOutput() . "\n" . $process->getOutput()) ?: 'Image conversion failed.');
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Image conversion returned invalid JSON.');
        }

        return $decoded;
    }

    private function buildAnnotations(Document $document, array $conversion): array
    {
        $annotations = [];
        $page = is_array($conversion['page'] ?? null) ? $conversion['page'] : [];
        $pageWidth = max(1.0, (float) ($page['width'] ?? 612));
        $pageHeight = max(1.0, (float) ($page['height'] ?? 792));
        $mode = (string) ($conversion['mode'] ?? 'reconstruct');
        $isImageBacked = $mode === 'image-backed';
        $sequence = 0;

        foreach (($conversion['shapes'] ?? []) as $shape) {
            if (!is_array($shape)) {
                continue;
            }

            $pdfX = $this->number($shape['pdfX'] ?? null, 0);
            $pdfY = $this->number($shape['pdfY'] ?? null, 0);
            $pdfWidth = max(1.0, $this->number($shape['pdfWidth'] ?? null, 1));
            $pdfHeight = max(1.0, $this->number($shape['pdfHeight'] ?? null, 1));

            $annotations[] = [
                'id' => $this->annotationId($document, 'shape', ++$sequence),
                'pageIndex' => 0,
                'type' => 'shape',
                'shapeType' => 'square',
                'pdfX' => $pdfX,
                'pdfY' => $pdfY,
                'pdfWidth' => min($pdfWidth, max(1.0, $pageWidth - $pdfX)),
                'pdfHeight' => min($pdfHeight, max(1.0, $pageHeight - $pdfY)),
                'strokeColor' => $this->hex($shape['strokeColor'] ?? null, '#111827'),
                'fillColor' => $this->hex($shape['fillColor'] ?? null, '#111827'),
                'strokeWidth' => max(1.0, $this->number($shape['strokeWidth'] ?? null, 1)),
                'strokeOpacity' => $this->clamp01($this->number($shape['strokeOpacity'] ?? null, 1)),
                'fillOpacity' => $this->clamp01($this->number($shape['fillOpacity'] ?? null, 1)),
                'strokeTransparent' => false,
                'fillTransparent' => false,
                'opacity' => 1,
                'rotation' => 0,
                'zIndex' => $isImageBacked ? 3 : 1,
                'userCreated' => true,
                'text' => '',
                '_originalBox' => ['x' => $pdfX, 'y' => $pdfY, 'w' => $pdfWidth, 'h' => $pdfHeight],
                '_originalPdfBox' => ['x' => $pdfX, 'y' => $pdfY, 'w' => $pdfWidth, 'h' => $pdfHeight],
            ];
        }

        foreach (($conversion['text'] ?? []) as $text) {
            if (!is_array($text)) {
                continue;
            }

            $value = trim((string) ($text['text'] ?? ''));
            if ($value === '') {
                continue;
            }

            $pdfX = $this->number($text['pdfX'] ?? null, 0);
            $pdfY = $this->number($text['pdfY'] ?? null, 0);
            $fontSize = max(6.0, min(96.0, $this->number($text['fontSize'] ?? null, 12)));
            $pdfHeight = max($fontSize * 1.2, $this->number($text['pdfHeight'] ?? null, $fontSize * 1.2));
            $pdfWidth = max($fontSize * 2, $this->number($text['pdfWidth'] ?? null, strlen($value) * $fontSize * 0.55) + 8);
            $pdfWidth = min($pdfWidth, max(1.0, $pageWidth - $pdfX));
            $pdfHeight = min($pdfHeight, max(1.0, $pageHeight - $pdfY));
            $color = $this->hex($text['textColor'] ?? null, '#111827');

            $annotations[] = [
                'id' => $this->annotationId($document, 'text', ++$sequence),
                'pageIndex' => 0,
                'type' => 'text',
                'text' => $value,
                'originalText' => $value,
                'pdfX' => $pdfX,
                'pdfY' => $pdfY,
                'pdfWidth' => $pdfWidth,
                'pdfHeight' => $pdfHeight,
                'fontSize' => $fontSize,
                'requestedFontSize' => $fontSize,
                'lineHeight' => round($fontSize * 1.18, 3),
                'fontFamily' => 'Helvetica',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => $color,
                'color' => $color,
                'backgroundColor' => 'transparent',
                'opacity' => 1,
                'underline' => false,
                'textAlign' => 'left',
                'verticalAlign' => 'top',
                'locked' => false,
                'zIndex' => 6,
                'savedTextOverlay' => true,
                'styleDirty' => ! $isImageBacked,
                'userCreated' => ! $isImageBacked,
                'userAuthored' => ! $isImageBacked,
                'userSizedTextBox' => true,
                'userForcedRichText' => true,
                'pdfjsEditorMode' => 'rich',
                'skipPdfjsSourceMask' => $isImageBacked,
                'imageToPdfOcr' => $isImageBacked,
                'pdfjsAnchorUid' => 'image-to-pdf-' . $sequence,
                'pdfjsSourceText' => '',
                'pdfjsSourceX' => $pdfX,
                'pdfjsSourceY' => $pdfY,
                'pdfjsSourceW' => $pdfWidth,
                'pdfjsSourceH' => $pdfHeight,
                'pdfjsSourcePageHeight' => $pageHeight,
                '_originalBox' => ['x' => $pdfX, 'y' => $pdfY, 'w' => $pdfWidth, 'h' => $pdfHeight],
                '_originalPdfBox' => ['x' => $pdfX, 'y' => $pdfY, 'w' => $pdfWidth, 'h' => $pdfHeight],
            ];
        }

        return $annotations;
    }

    private function annotationId(Document $document, string $type, int $sequence): string
    {
        return 'image_to_pdf_' . $document->id . '_' . $type . '_' . $sequence . '_' . Str::lower(Str::random(6));
    }

    private function documentName(string $originalName): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME) ?: 'Image';
        $base = trim(preg_replace('/\s+/', ' ', $base)) ?: 'Image';

        return Str::limit($base, 120, '') . ' - editable.pdf';
    }

    private function conversionMode(string $mode): string
    {
        return in_array($mode, ['reconstruct', 'image-backed'], true) ? $mode : 'reconstruct';
    }

    private function currentOwnershipPayload(): array
    {
        $webUserId = Auth::guard('web')->id();
        if ($webUserId !== null) {
            return ['user_id' => (int) $webUserId, 'admin_id' => null];
        }

        $adminId = Auth::guard('admin')->id();
        if ($adminId !== null) {
            return ['user_id' => null, 'admin_id' => (int) $adminId];
        }

        return ['user_id' => null, 'admin_id' => null];
    }

    private function pdfStateOwnershipPayload(Document $document, string $sessionId): array
    {
        return [
            'user_id' => $document->user_id,
            'admin_id' => $document->admin_id,
            'user_email' => ($document->user_id !== null || $document->admin_id !== null) ? null : 'image-to-pdf@local',
            'session_id' => $sessionId,
        ];
    }

    private function createOriginalBackup(string $storedPath): ?string
    {
        if (!$storedPath || !Storage::exists($storedPath)) {
            return null;
        }

        Storage::makeDirectory('documents/originals');
        $backupPath = 'documents/originals/' . pathinfo($storedPath, PATHINFO_FILENAME) . '_original.pdf';

        try {
            Storage::copy($storedPath, $backupPath);
        } catch (\Throwable $e) {
            Log::warning('Failed to create image-to-pdf original backup', [
                'path' => $storedPath,
                'backup_path' => $backupPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $backupPath;
    }

    private function resolvePythonBinary(?string $requiredModule = null): string
    {
        $candidates = array_values(array_unique([
            base_path('.venv/bin/python'),
            base_path('venv/bin/python'),
            base_path('python/venv/bin/python'),
            '/usr/bin/python3',
            'python3',
        ]));

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '/') && !is_executable($candidate)) {
                continue;
            }

            if (!$requiredModule) {
                return $candidate;
            }

            $probe = new Process([$candidate, '-c', 'import ' . $requiredModule]);
            $probe->setTimeout(10);
            $probe->run();
            if ($probe->isSuccessful()) {
                return $candidate;
            }
        }

        return 'python3';
    }

    private function number(mixed $value, float $fallback): float
    {
        return is_numeric($value) ? (float) $value : $fallback;
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private function hex(mixed $value, string $fallback): string
    {
        $hex = is_string($value) ? trim($value) : '';
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            return strtolower($hex);
        }

        return $fallback;
    }
}
