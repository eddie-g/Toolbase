<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\GuidedTemplate;
use App\Models\PdfAcroForm;
use App\Models\PdfState;
use App\Services\PdfAnnotationAssetService;
use App\Models\User;
use App\Models\UserActivity;
use App\Models\UserPdfMonthlyUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    private const MONTHLY_UPLOAD_LIMIT = 100;
    private const MONTHLY_ACTION_LIMIT = 1000;
    private const PDF_ACRO_FORM_BASE_SESSION = '__document_acro_form__';

    private function resolveEditorActor(): mixed
    {
        return Auth::guard('web')->user() ?? Auth::guard('admin')->user();
    }

    private function hasEditorAuthentication(): bool
    {
        return Auth::guard('web')->check() || Auth::guard('admin')->check();
    }

    private function resolveEditorEmail(string $fallback = 'guest'): ?string
    {
        $actor = $this->resolveEditorActor();
        $email = is_object($actor) && isset($actor->email) ? trim((string) $actor->email) : '';

        return $email !== '' ? $email : $fallback;
    }

    private function resolvePythonBinaryForPdfEditor(?string $requiredModule = null): string
    {
        $candidates = array_values(array_unique([
            '/usr/bin/python3',
            'python3',
            base_path('python/venv/bin/python'),
        ]));

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '/') && !is_executable($candidate)) {
                continue;
            }

            if (!$requiredModule) {
                return $candidate;
            }

            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $requiredModule)) {
                break;
            }

            $probeOutput = [];
            $probeExitCode = 1;
            $probeCommand = sprintf(
                '%s -c %s 2>&1',
                escapeshellarg($candidate),
                escapeshellarg("import {$requiredModule}")
            );
            exec($probeCommand, $probeOutput, $probeExitCode);

            if ($probeExitCode === 0) {
                return $candidate;
            }
        }

        return 'python3';
    }

    private function annotationAssets(): PdfAnnotationAssetService
    {
        return app(PdfAnnotationAssetService::class);
    }

    private function normalizeDocumentOriginalName(Document $document, string $value): string
    {
        $fallbackName = (string) ($document->original_name ?: basename((string) $document->path));
        $fallbackBase = trim((string) pathinfo($fallbackName, PATHINFO_FILENAME));

        $name = preg_replace('/[<>:"\/\\|?*\x00-\x1F]+/u', ' ', trim($value));
        $name = preg_replace('/\s+/u', ' ', (string) $name);
        $name = trim((string) $name);

        if ($name === '') {
            $name = $fallbackBase !== '' ? $fallbackBase : 'document';
        }

        $extension = strtolower((string) pathinfo($fallbackName, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = match (strtolower((string) $document->mime_type)) {
                'application/pdf' => 'pdf',
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                default => '',
            };
        }

        if ($extension !== '') {
            $currentExtension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            if ($currentExtension !== '' && $currentExtension !== $extension) {
                $name = trim((string) pathinfo($name, PATHINFO_FILENAME));
            }

            if (!str_ends_with(strtolower($name), '.' . $extension)) {
                $name = rtrim($name, ". \t\n\r\0\x0B") . '.' . $extension;
            }
        }

        return $name;
    }

    private function normalizeAnnotationsForPersistence(Document $document, array $annotations): array
    {
        $annotationAssets = $this->annotationAssets();

        return array_map(static function ($annotation) use ($annotationAssets, $document) {
            return is_array($annotation)
                ? $annotationAssets->normalizeForPersistence($document, $annotation)
                : $annotation;
        }, $annotations);
    }

    private function upsertPdfStateSessionSnapshot(
        Document $document,
        string $sessionId,
        array $annotations,
        string $state = 'saved'
    ): int {
        if ($sessionId === '') {
            return 0;
        }

        $normalizedAnnotations = array_values(array_filter($annotations, 'is_array'));
        $annotationIds = array_values(array_filter(array_map(
            static fn (array $annotation) => is_string($annotation['id'] ?? null)
                ? trim((string) $annotation['id'])
                : '',
            $normalizedAnnotations
        )));

        DB::transaction(function () use ($document, $sessionId, $normalizedAnnotations, $annotationIds, $state) {
            $existingRows = PdfState::query()
                ->where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->get()
                ->keyBy(static fn (PdfState $record) => trim((string) data_get($record->annotation_data, 'id', '')));

            $seenIds = [];
            foreach ($normalizedAnnotations as $annotation) {
                $annotationId = is_string($annotation['id'] ?? null)
                    ? trim((string) $annotation['id'])
                    : '';
                if ($annotationId === '') {
                    continue;
                }

                $pageIndex = isset($annotation['pageIndex']) && is_numeric($annotation['pageIndex'])
                    ? (int) $annotation['pageIndex']
                    : null;

                /** @var PdfState|null $existing */
                $existing = $existingRows->get($annotationId);
                if ($existing) {
                    $existing->update([
                        'annotation_data' => $annotation,
                        'page_number' => $pageIndex,
                        'state' => $state,
                    ]);
                } else {
                    PdfState::create([
                        'document_id' => $document->id,
                        'user_email' => null,
                        'session_id' => $sessionId,
                        'page_number' => $pageIndex,
                        'annotation_data' => $annotation,
                        'state' => $state,
                    ]);
                }

                $seenIds[$annotationId] = true;
            }

            $staleIds = [];
            foreach ($existingRows as $existingId => $record) {
                if ($existingId === '' || isset($seenIds[$existingId])) {
                    continue;
                }
                $staleIds[] = $record->id;
            }

            if (!empty($staleIds)) {
                PdfState::query()
                    ->where('document_id', $document->id)
                    ->where('session_id', $sessionId)
                    ->whereIn('id', $staleIds)
                    ->delete();
            }
        });

        return count($annotationIds);
    }

    private function normalizeAcroFormEntriesForPersistence(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $key = trim((string) ($entry['key'] ?? $entry['fieldName'] ?? ''));
            if ($key === '') {
                continue;
            }

            $fieldName = trim((string) ($entry['fieldName'] ?? $key));
            $pageIndex = null;
            $rawPageIndex = $entry['pageIndex'] ?? $entry['page_num'] ?? $entry['pageNumber'] ?? null;
            if (is_numeric($rawPageIndex)) {
                $pageIndex = max(0, (int) $rawPageIndex);
            }

            $value = $entry['value'] ?? null;
            if (is_array($value)) {
                $value = array_values(array_map(static fn ($item) => (string) ($item ?? ''), $value));
            } elseif (is_bool($value)) {
                $value = $value;
            } elseif ($value === null) {
                $value = '';
            } else {
                $value = (string) $value;
            }

            $rect = null;
            if (is_array($entry['rect'] ?? null) && count($entry['rect']) >= 4) {
                $nextRect = array_slice($entry['rect'], 0, 4);
                $nextRect = array_map(static fn ($value) => is_numeric($value) ? (float) $value : null, $nextRect);
                if (!in_array(null, $nextRect, true)) {
                    $rect = $nextRect;
                }
            }

            $normalized[$key] = [
                'key' => $key,
                'fieldName' => $fieldName !== '' ? $fieldName : $key,
                'pageIndex' => $pageIndex,
                'fieldType' => strtoupper(trim((string) ($entry['fieldType'] ?? ''))),
                'checkBox' => (bool) ($entry['checkBox'] ?? false),
                'radioButton' => (bool) ($entry['radioButton'] ?? false),
                'combo' => (bool) ($entry['combo'] ?? false),
                'multiLine' => (bool) ($entry['multiLine'] ?? false),
                'multiSelect' => (bool) ($entry['multiSelect'] ?? false),
                'exportValue' => trim((string) ($entry['exportValue'] ?? '')),
                'rect' => $rect,
                'textColor' => is_string($entry['textColor'] ?? null) ? trim((string) $entry['textColor']) : null,
                'value' => $value,
            ];
        }

        return array_values($normalized);
    }

    private function upsertPdfAcroFormSessionState(
        Document $document,
        string $sessionId,
        array $entries,
        ?int $userId,
        string $state = 'saved'
    ): int {
        if ($sessionId === '') {
            return 0;
        }

        $normalizedEntries = $this->normalizeAcroFormEntriesForPersistence($entries);
        $keys = array_values(array_filter(array_map(
            static fn (array $entry) => trim((string) ($entry['key'] ?? '')),
            $normalizedEntries
        )));

        DB::transaction(function () use ($document, $sessionId, $normalizedEntries, $userId, $state, $keys) {
            foreach ($normalizedEntries as $entry) {
                $fieldKey = trim((string) ($entry['key'] ?? ''));
                if ($fieldKey === '') {
                    continue;
                }

                $existing = PdfAcroForm::query()
                    ->where('document_id', $document->id)
                    ->where('sess_id', $sessionId)
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.key')) = ?", [$fieldKey])
                    ->first();

                $payload = [
                    'user_id' => $userId,
                    'page_num' => isset($entry['pageIndex']) && $entry['pageIndex'] !== null ? (int) $entry['pageIndex'] : null,
                    'data' => $entry,
                    'state' => $state,
                ];

                if ($existing) {
                    $existing->update($payload);
                } else {
                    PdfAcroForm::create(array_merge($payload, [
                        'document_id' => $document->id,
                        'sess_id' => $sessionId,
                    ]));
                }
            }

            $cleanupQuery = PdfAcroForm::query()
                ->where('document_id', $document->id)
                ->where('sess_id', $sessionId);

            if (empty($keys)) {
                $cleanupQuery->delete();
            } else {
                $quotedKeys = implode(',', array_fill(0, count($keys), '?'));
                $cleanupQuery
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.key')) NOT IN ({$quotedKeys})", $keys)
                    ->delete();
            }
        });

        return count($normalizedEntries);
    }

    private function materializePdfAcroFormFields(Document $document, string $pdfPath, string $pythonBinary): int
    {
        if (!file_exists($pdfPath)) {
            return 0;
        }

        $script = base_path('python/pdf-editor/extract_acro_form_fields.py');
        $command = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($pdfPath)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        if ($returnCode !== 0) {
            Log::warning('Failed to extract AcroForm fields for materialization', [
                'document_id' => $document->id,
                'return_code' => $returnCode,
                'output' => implode("\n", $output),
            ]);
            return 0;
        }

        $decoded = json_decode(implode("\n", $output), true);
        $fields = $this->normalizeAcroFormEntriesForPersistence($decoded['fields'] ?? []);
        $baseSessionId = self::PDF_ACRO_FORM_BASE_SESSION;

        DB::transaction(function () use ($document, $fields, $baseSessionId) {
            PdfAcroForm::query()
                ->where('document_id', $document->id)
                ->where('sess_id', $baseSessionId)
                ->delete();

            foreach ($fields as $field) {
                PdfAcroForm::create([
                    'document_id' => $document->id,
                    'user_id' => $document->user_id,
                    'sess_id' => $baseSessionId,
                    'page_num' => isset($field['pageIndex']) && $field['pageIndex'] !== null ? (int) $field['pageIndex'] : null,
                    'data' => $field,
                    'state' => 'extracted',
                ]);
            }
        });

        return count($fields);
    }

    private function ensurePdfAcroFormMaterialized(Document $document, string $pdfPath, string $pythonBinary): int
    {
        $latestMaterializedAt = PdfAcroForm::query()
            ->where('document_id', $document->id)
            ->where('sess_id', self::PDF_ACRO_FORM_BASE_SESSION)
            ->max('updated_at');

        $pdfModifiedAt = file_exists($pdfPath) ? filemtime($pdfPath) : 0;
        $materializedTimestamp = $latestMaterializedAt ? strtotime((string) $latestMaterializedAt) : 0;

        if (!$latestMaterializedAt || $pdfModifiedAt > $materializedTimestamp) {
            return $this->materializePdfAcroFormFields($document, $pdfPath, $pythonBinary);
        }

        return (int) PdfAcroForm::query()
            ->where('document_id', $document->id)
            ->where('sess_id', self::PDF_ACRO_FORM_BASE_SESSION)
            ->count();
    }

    private function applyAcroFormEntriesToPdf(string $inputPdfPath, array $entries, string $pythonBinary): array
    {
        if (!file_exists($inputPdfPath) || empty($entries)) {
            return ['success' => true, 'output_pdf_path' => $inputPdfPath];
        }

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $entriesPath = $tempDir . '/acro_form_entries_' . uniqid('', true) . '.json';
        $outputPdfPath = $tempDir . '/acro_form_applied_' . uniqid('', true) . '.pdf';
        $entriesJson = json_encode(array_values($entries), JSON_INVALID_UTF8_SUBSTITUTE);

        if ($entriesJson === false || @file_put_contents($entriesPath, $entriesJson) === false) {
            return ['success' => false, 'message' => 'Failed to prepare AcroForm entries payload.'];
        }

        $script = base_path('python/pdf-editor/apply_acro_form_values.py');
        $command = sprintf(
            '%s %s %s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($inputPdfPath),
            escapeshellarg($entriesPath),
            escapeshellarg($outputPdfPath)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        @unlink($entriesPath);

        if ($returnCode !== 0 || !file_exists($outputPdfPath)) {
            @unlink($outputPdfPath);
            return [
                'success' => false,
                'message' => 'Failed to apply AcroForm values to PDF.',
                'error' => implode("\n", $output),
            ];
        }

        return [
            'success' => true,
            'output_pdf_path' => $outputPdfPath,
        ];
    }

    private function prepareAnnotationsForPython(array $annotations): array
    {
        $annotationAssets = $this->annotationAssets();

        return array_values(array_filter(array_map(static function ($annotation) use ($annotationAssets) {
            if (!is_array($annotation)) {
                return null;
            }

            $enriched = $annotationAssets->enrichForPython($annotation);
            $annotationType = strtolower((string) ($enriched['type'] ?? ''));

            // Interactive PDF form fields are exported client-side with pdf-lib.
            // The Python stamping pipeline only supports visual annotations.
            if ($annotationType === 'field') {
                return null;
            }

            return $enriched;
        }, $annotations)));
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
            Log::warning('Failed to create original PDF backup', [
                'path' => $storedPath,
                'backup_path' => $backupPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $backupPath;
    }

    private function findLatestFitzExtraction(int $documentId, ?string $userEmail = null, ?string $sessionId = null): ?object
    {
        if ($userEmail && $userEmail !== 'guest') {
            $extraction = DB::table('pdf_extractions_fitz')
                ->where('document_id', $documentId)
                ->where('user_email', $userEmail)
                ->orderBy('id', 'desc')
                ->first();

            if ($extraction) {
                return $extraction;
            }
        }

        if ($sessionId) {
            $extraction = DB::table('pdf_extractions_fitz')
                ->where('document_id', $documentId)
                ->where('session_id', $sessionId)
                ->orderBy('id', 'desc')
                ->first();

            if ($extraction) {
                return $extraction;
            }
        }

        return DB::table('pdf_extractions_fitz')
            ->where('document_id', $documentId)
            ->orderBy('id', 'desc')
            ->first();
    }

    private function extractedPdfStateSessionId(Document $document): string
    {
        return 'document_' . $document->id . '_extracted';
    }

    private function runFitzExtraction(
        Document $document,
        string $fullPath,
        string $userEmail,
        string $sessionId,
        string $pythonBinary
    ): array {
        $pythonScript = base_path('python/pdf-editor/extract_pdf_pymupdf.py');
        $command = sprintf(
            '%s %s %s %d %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($fullPath),
            $document->id,
            escapeshellarg($userEmail),
            escapeshellarg($sessionId)
        );

        $output = [];
        $returnCode = 1;
        exec($command, $output, $returnCode);

        return [$returnCode, $output];
    }

    private function collapseAdjacentDuplicatePromotedTokenRunsForMaterialization($value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', trim((string) $value)));
        $normalized = trim((string) $normalized);
        if ($normalized === '') {
            return '';
        }

        $tokens = preg_split('/ +/', $normalized) ?: [];
        if (count($tokens) < 2) {
            return $normalized;
        }

        $shouldCollapseSegment = static function (array $segment): bool {
            $wordTokens = array_values(array_filter($segment, static fn ($token) => preg_match('/[A-Za-z0-9]/', (string) $token)));
            if (empty($wordTokens)) {
                return false;
            }
            if (count($wordTokens) > 1) {
                return true;
            }

            return strlen((string) $wordTokens[0]) >= 5;
        };

        $collapsed = array_values(array_filter($tokens, static fn ($token) => $token !== ''));
        do {
            $changed = false;
            $maxSegmentLength = min(8, intdiv(count($collapsed), 2));
            for ($segmentLength = $maxSegmentLength; $segmentLength >= 1; $segmentLength--) {
                $limit = count($collapsed) - ($segmentLength * 2);
                for ($index = 0; $index <= $limit; $index++) {
                    $leftSegment = array_slice($collapsed, $index, $segmentLength);
                    $rightSegment = array_slice($collapsed, $index + $segmentLength, $segmentLength);
                    $duplicateSegment = true;

                    foreach ($leftSegment as $offset => $token) {
                        if (Str::lower((string) $token) !== Str::lower((string) ($rightSegment[$offset] ?? ''))) {
                            $duplicateSegment = false;
                            break;
                        }
                    }

                    if (!$duplicateSegment || !$shouldCollapseSegment($leftSegment)) {
                        continue;
                    }

                    array_splice($collapsed, $index + $segmentLength, $segmentLength);
                    $changed = true;
                    break 2;
                }
            }
        } while ($changed);

        return implode(' ', $collapsed);
    }

    private function sanitizePromotedExtractionLineForMaterialization($value): string
    {
        $normalized = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], (string) $value);
        $normalized = preg_replace('/[ \t]+$/um', '', $normalized);

        return $this->collapseAdjacentDuplicatePromotedTokenRunsForMaterialization($normalized);
    }

    private function sanitizePromotedExtractionTextForMaterialization($value): string
    {
        $normalized = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], (string) $value);
        $normalized = preg_replace('/[ \t]+\n/u', "\n", $normalized);
        $normalized = preg_replace('/\n{3,}/u', "\n\n", (string) $normalized);

        $result = [];
        foreach (explode("\n", (string) $normalized) as $index => $line) {
            $sanitizedLine = $this->sanitizePromotedExtractionLineForMaterialization($line);
            if ($sanitizedLine !== '' && $index > 0 && (($result[count($result) - 1] ?? null) === $sanitizedLine)) {
                continue;
            }
            $result[] = $sanitizedLine;
        }

        return trim(implode("\n", $result));
    }

    private function normalizePdfEditableFontFamilyForMaterialization(?string $fontName): string
    {
        if (!$fontName) {
            return '';
        }

        $cleaned = trim(str_replace(['"', "'"], '', (string) $fontName));
        if ($cleaned === '') {
            return '';
        }

        if (str_contains($cleaned, '+')) {
            [$prefix, $suffix] = array_pad(explode('+', $cleaned, 2), 2, '');
            if (strlen($prefix) === 6 && $suffix !== '') {
                $cleaned = $suffix;
            }
        }

        if (preg_match('/^[A-Za-z]{6}[A-Z]/', $cleaned) && strlen($cleaned) > 7) {
            $withoutPrefix = substr($cleaned, 6);
            if ($withoutPrefix !== false && preg_match('/^[A-Z][a-z]/', $withoutPrefix)) {
                $cleaned = $withoutPrefix;
            }
        }

        $basePart = preg_split('/[-_,]/', $cleaned, 2)[0] ?? $cleaned;
        $weightSuffixes = [
            'Thin', 'Hairline', 'ExtraLight', 'UltraLight', 'Light',
            'Regular', 'Medium', 'SemiBold', 'DemiBold', 'Bold',
            'ExtraBold', 'UltraBold', 'Black', 'Heavy',
        ];
        $family = $basePart;
        foreach ($weightSuffixes as $suffix) {
            if (Str::endsWith($family, $suffix) && strlen($family) > strlen($suffix)) {
                $family = substr($family, 0, strlen($family) - strlen($suffix));
                break;
            }
        }

        return rtrim(trim($family), ", \t\n\r\0\x0B");
    }

    private function normalizeBuiltinAnnotationFontFamilyForMaterialization(?string $fontFamily): string
    {
        if (!$fontFamily) {
            return 'Helvetica';
        }

        $lower = Str::lower(str_replace(['"', "'", ' '], '', (string) $fontFamily));

        return match (true) {
            str_contains($lower, 'arial') => 'Helvetica',
            str_contains($lower, 'arimo'), str_contains($lower, 'helvetica'), str_contains($lower, 'nimbussans') => 'Helvetica',
            str_contains($lower, 'verdana'), str_contains($lower, 'geneva') => 'Verdana',
            str_contains($lower, 'trebuchet') => 'TrebuchetMS',
            str_contains($lower, 'gelasio'), str_contains($lower, 'georgia') => 'Georgia',
            str_contains($lower, 'palatino'), str_contains($lower, 'bookantiqua') => 'Palatino',
            str_contains($lower, 'tinos'), str_contains($lower, 'garamond'), str_contains($lower, 'baskerville') => 'Garamond',
            str_contains($lower, 'times') => 'TimesRoman',
            str_contains($lower, 'courier'), str_contains($lower, 'cousine'), str_contains($lower, 'mono') => 'Courier',
            default => 'Helvetica',
        };
    }

    private function colorToHexForMaterialization(?string $color): string
    {
        $value = trim((string) $color);
        if ($value === '') {
            return '#000000';
        }

        if (preg_match('/^#[0-9a-f]{3}$/i', $value)) {
            return sprintf(
                '#%s%s%s%s%s%s',
                $value[1],
                $value[1],
                $value[2],
                $value[2],
                $value[3],
                $value[3]
            );
        }

        if (preg_match('/^#[0-9a-f]{6}$/i', $value)) {
            return strtoupper($value);
        }

        return '#000000';
    }

    private function normalizePromotedAnnotationColorForMaterialization(array $block): string
    {
        if (!empty($block['hex_color'])) {
            return $this->colorToHexForMaterialization((string) $block['hex_color']);
        }

        if (array_key_exists('color', $block) && $block['color'] !== null) {
            if (is_numeric($block['color'])) {
                return $this->colorToHexForMaterialization('#' . str_pad(dechex((int) $block['color']), 6, '0', STR_PAD_LEFT));
            }

            return $this->colorToHexForMaterialization((string) $block['color']);
        }

        return '#000000';
    }

    private function clusterPromotedExtractionLineEntriesForMaterialization(array $lineEntries): array
    {
        if (empty($lineEntries)) {
            return [];
        }

        usort($lineEntries, static function (array $leftEntry, array $rightEntry): int {
            $leftTop = (float) ($leftEntry['bbox'][1] ?? 0);
            $rightTop = (float) ($rightEntry['bbox'][1] ?? 0);
            if (abs($leftTop - $rightTop) > 0.25) {
                return $leftTop <=> $rightTop;
            }

            $leftX = (float) ($leftEntry['bbox'][0] ?? 0);
            $rightX = (float) ($rightEntry['bbox'][0] ?? 0);
            if (abs($leftX - $rightX) > 0.25) {
                return $leftX <=> $rightX;
            }

            return ((int) ($leftEntry['index'] ?? 0)) <=> ((int) ($rightEntry['index'] ?? 0));
        });

        $clusters = [];
        $currentCluster = [$lineEntries[0]];

        $normalizeLineText = static fn (array $entry): string => trim((string) ($entry['text'] ?? ''));
        $isNumberedLine = static fn (array $entry): bool => preg_match('/^\(?\d+\)?[.)]/', $normalizeLineText($entry)) === 1;
        $isFooterLine = static fn (array $entry): bool => preg_match('/^The form of this addendum/i', $normalizeLineText($entry)) === 1;

        $shouldSplitCluster = static function (array $previousEntry, array $nextEntry) use ($isFooterLine, $isNumberedLine): bool {
            $prevBox = is_array($previousEntry['bbox'] ?? null) ? $previousEntry['bbox'] : [0, 0, 0, 0];
            $nextBox = is_array($nextEntry['bbox'] ?? null) ? $nextEntry['bbox'] : [0, 0, 0, 0];
            $prevTop = (float) ($prevBox[1] ?? 0);
            $prevBottom = (float) ($prevBox[3] ?? $prevTop);
            $nextTop = (float) ($nextBox[1] ?? 0);
            $nextBottom = (float) ($nextBox[3] ?? $nextTop);
            $prevHeight = max(1, $prevBottom - $prevTop);
            $nextHeight = max(1, $nextBottom - $nextTop);
            $verticalGap = $nextTop - $prevBottom;
            $overlapHeight = max(0, min($prevBottom, $nextBottom) - max($prevTop, $nextTop));
            $overlapRatio = $overlapHeight / max(1, min($prevHeight, $nextHeight));

            if ($overlapRatio >= 0.2) {
                return false;
            }

            if ($isNumberedLine($nextEntry) && !$isNumberedLine($previousEntry) && $overlapRatio < 0.3) {
                return true;
            }
            if ($isFooterLine($nextEntry) && !$isFooterLine($previousEntry)) {
                return true;
            }

            $splitGapThreshold = max(4.5, min(14, max($prevHeight, $nextHeight) * 0.6));
            if ($verticalGap > $splitGapThreshold) {
                return true;
            }

            $prevLeft = (float) ($prevBox[0] ?? 0);
            $nextLeft = (float) ($nextBox[0] ?? 0);
            if (($nextLeft - $prevLeft) > max(12, min($prevHeight, $nextHeight) * 0.3)) {
                return true;
            }

            $normalizedNext = preg_replace('/[, ]+/', '', trim((string) ($nextEntry['text'] ?? '')));
            $normalizedPrev = trim((string) ($previousEntry['text'] ?? ''));
            $amountOnly = preg_match('/^[0-9]+$/', (string) $normalizedNext) === 1;
            if ($amountOnly && preg_match('/(less than|\$|amount|value)/i', $normalizedPrev)) {
                return false;
            }

            return false;
        };

        for ($index = 1, $count = count($lineEntries); $index < $count; $index++) {
            $nextEntry = $lineEntries[$index];
            $previousEntry = $currentCluster[count($currentCluster) - 1];
            if ($shouldSplitCluster($previousEntry, $nextEntry)) {
                $clusters[] = $currentCluster;
                $currentCluster = [$nextEntry];
                continue;
            }

            $currentCluster[] = $nextEntry;
        }

        if (!empty($currentCluster)) {
            $clusters[] = $currentCluster;
        }

        return array_values(array_filter($clusters, static fn (array $cluster) => !empty($cluster)));
    }

    private function buildPromotedStateAnnotationFromExtractionBlock(
        array $block,
        array $pageData,
        array $textLines = [],
        array $sourceLineBBoxes = [],
        string $sourceKeySuffix = ''
    ): ?array {
        $normalizedLines = array_values(array_filter(array_map(
            fn ($line) => $this->sanitizePromotedExtractionLineForMaterialization($line),
            is_array($textLines) ? $textLines : []
        ), static fn ($line) => $line !== ''));

        $blockText = !empty($normalizedLines)
            ? implode("\n", $normalizedLines)
            : $this->sanitizePromotedExtractionTextForMaterialization($block['text'] ?? '');

        if ($blockText === '') {
            return null;
        }

        $normalizedLineBBoxes = array_values(array_map(
            static fn (array $bbox): array => array_map(static fn ($value): float => (float) $value, array_slice($bbox, 0, 4)),
            array_values(array_filter(
                is_array($sourceLineBBoxes) ? $sourceLineBBoxes : [],
                static fn ($bbox) => is_array($bbox) && count($bbox) >= 4
            ))
        ));

        $bboxSource = !empty($normalizedLineBBoxes)
            ? $normalizedLineBBoxes
            : [[
                (float) ($block['left'] ?? 0),
                (float) ($block['top'] ?? 0),
                (float) ($block['left'] ?? 0) + (float) ($block['width'] ?? 0),
                (float) ($block['top'] ?? 0) + (float) ($block['height'] ?? 0),
            ]];

        $left = min(array_map(static fn (array $bbox): float => (float) ($bbox[0] ?? 0), $bboxSource));
        $top = min(array_map(static fn (array $bbox): float => (float) ($bbox[1] ?? 0), $bboxSource));
        $right = max(array_map(static fn (array $bbox): float => (float) ($bbox[2] ?? $left), $bboxSource));
        $bottom = max(array_map(static fn (array $bbox): float => (float) ($bbox[3] ?? $top), $bboxSource));
        $width = $right - $left;
        $height = $bottom - $top;
        $pageHeight = (float) ($pageData['height'] ?? 0);

        if ($width <= 1 || $height <= 1 || $pageHeight <= 0) {
            return null;
        }

        $lineCount = max(1, count($normalizedLines) ?: count(explode("\n", $blockText)));
        $fontWeight = !empty($block['font_weight'])
            ? (string) $block['font_weight']
            : (!empty($block['bold']) ? '700' : '400');
        $extractedLineHeight = !empty($normalizedLineBBoxes)
            ? array_sum(array_map(static fn (array $bbox): float => max(1, (float) ($bbox[3] ?? 0) - (float) ($bbox[1] ?? 0)), $normalizedLineBBoxes)) / count($normalizedLineBBoxes)
            : ((float) ($block['avg_line_height'] ?? $block['line_height'] ?? ($height / $lineCount)));
        $pageNumber = max(1, (int) ($pageData['page_number'] ?? 1));
        $rootSourceKey = 'block-' . $pageNumber . '-' . ((int) ($block['block_num'] ?? 0));
        $promotedSourceKey = $sourceKeySuffix !== '' ? ($rootSourceKey . '-' . $sourceKeySuffix) : $rootSourceKey;
        $fontSourceName = trim((string) ($block['font'] ?? ''));
        $resolvedFontFamily = $this->normalizePdfEditableFontFamilyForMaterialization($fontSourceName);
        if ($resolvedFontFamily === '') {
            $resolvedFontFamily = $this->normalizeBuiltinAnnotationFontFamilyForMaterialization($fontSourceName ?: 'Helvetica');
        }

        return [
            'id' => 'promoted_' . $pageNumber . '_' . ((int) ($block['block_num'] ?? 0)) . ($sourceKeySuffix !== '' ? '_' . preg_replace('/[^a-z0-9_-]+/i', '_', $sourceKeySuffix) : ''),
            'type' => 'text',
            'text' => $blockText,
            'originalText' => $blockText,
            'pageIndex' => $pageNumber - 1,
            'pdfX' => $left,
            'pdfY' => $pageHeight - ($top + $height),
            'pdfWidth' => $width,
            'pdfHeight' => $height,
            'keepBounds' => true,
            'fontSize' => max(6, (float) ($block['font_size'] ?? 12)),
            'fontFamily' => $resolvedFontFamily,
            'fontSourceName' => $fontSourceName,
            'lineHeight' => $extractedLineHeight > 0 ? $extractedLineHeight : null,
            'textColor' => $this->normalizePromotedAnnotationColorForMaterialization($block),
            'backgroundColor' => 'transparent',
            'fontWeight' => $fontWeight,
            'fontStyle' => !empty($block['italic']) ? 'italic' : 'normal',
            'underline' => !empty($block['underline']),
            'textAlign' => 'left',
            'opacity' => 1,
            'rotation' => 0,
            'promotedFromExtraction' => true,
            'promotedDirty' => false,
            'promotedSourceKey' => $promotedSourceKey,
            'promotedSourceBlockNum' => (int) ($block['block_num'] ?? 0),
            'promotedSourcePage' => $pageNumber,
            'sourceBlockLeft' => $left,
            'sourceBlockTop' => $top,
            'sourceBlockWidth' => $width,
            'sourceBlockHeight' => $height,
            'sourcePageHeight' => $pageHeight,
            'sourceTextLines' => !empty($normalizedLines)
                ? array_values($normalizedLines)
                : array_values(array_filter(array_map(
                    fn ($line) => $this->sanitizePromotedExtractionLineForMaterialization($line),
                    explode("\n", $blockText)
                ), static fn ($line) => $line !== '')),
            'sourceLineBBoxes' => $normalizedLineBBoxes,
            'sourceSpans' => is_array($block['spans'] ?? null) ? array_values($block['spans']) : [],
        ];
    }

    private function buildPromotedStateAnnotationsFromExtractionBlock(array $block, array $pageData): array
    {
        $textLines = array_values(array_filter(array_map(
            fn ($line) => $this->sanitizePromotedExtractionLineForMaterialization($line),
            is_array($block['text_lines'] ?? null) ? $block['text_lines'] : []
        ), static fn ($line) => $line !== ''));
        $sourceLineBBoxes = array_values(array_filter(
            is_array($block['line_bboxes'] ?? null) ? $block['line_bboxes'] : [],
            static fn ($bbox) => is_array($bbox) && count($bbox) >= 4
        ));

        $baseAnnotation = $this->buildPromotedStateAnnotationFromExtractionBlock(
            $block,
            $pageData,
            $textLines,
            $sourceLineBBoxes
        );
        if (!$baseAnnotation) {
            return [];
        }

        if (count($textLines) < 2 || count($sourceLineBBoxes) < 2 || count($textLines) !== count($sourceLineBBoxes)) {
            return [$baseAnnotation];
        }

        $lineEntries = [];
        foreach ($sourceLineBBoxes as $index => $bbox) {
            $lineEntries[] = [
                'index' => $index,
                'text' => $textLines[$index] ?? '',
                'bbox' => $bbox,
            ];
        }

        $clusters = $this->clusterPromotedExtractionLineEntriesForMaterialization($lineEntries);
        if (count($clusters) <= 1) {
            return [$baseAnnotation];
        }

        $splitAnnotations = [];
        foreach ($clusters as $cluster) {
            $clusterStartIndex = (int) ($cluster[0]['index'] ?? 0);
            $clusterEndIndex = (int) ($cluster[count($cluster) - 1]['index'] ?? $clusterStartIndex);
            $clusterAnnotation = $this->buildPromotedStateAnnotationFromExtractionBlock(
                $block,
                $pageData,
                array_map(static fn (array $entry): string => (string) ($entry['text'] ?? ''), $cluster),
                array_map(static fn (array $entry): array => $entry['bbox'], $cluster),
                'lines-' . $clusterStartIndex . '-' . $clusterEndIndex
            );
            if ($clusterAnnotation) {
                $splitAnnotations[] = $clusterAnnotation;
            }
        }

        return !empty($splitAnnotations) ? $splitAnnotations : [$baseAnnotation];
    }

    private function buildPromotedStateAnnotationsFromExtractionData(array $extractionData): array
    {
        $annotations = [];

        foreach ($extractionData as $pageData) {
            if (!is_array($pageData)) {
                continue;
            }

            $blocks = is_array($pageData['blocks'] ?? null) ? $pageData['blocks'] : [];
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }

                foreach ($this->buildPromotedStateAnnotationsFromExtractionBlock($block, $pageData) as $annotation) {
                    if (is_array($annotation) && !empty($annotation['id'])) {
                        $annotations[] = $annotation;
                    }
                }
            }
        }

        return $annotations;
    }

    private function materializeFitzExtractionToPdfState(Document $document, $extractionRow): int
    {
        if (!$extractionRow) {
            return 0;
        }

        $rawExtractionData = is_array($extractionRow)
            ? ($extractionRow['extraction_data'] ?? null)
            : ($extractionRow->extraction_data ?? null);
        $extractionData = is_array($rawExtractionData)
            ? $rawExtractionData
            : json_decode((string) $rawExtractionData, true);

        if (!is_array($extractionData)) {
            return 0;
        }

        $annotations = $this->normalizeAnnotationsForPersistence(
            $document,
            $this->buildPromotedStateAnnotationsFromExtractionData($extractionData)
        );
        $annotationIds = array_values(array_unique(array_filter(array_map(
            static fn ($annotation) => is_array($annotation) ? trim((string) ($annotation['id'] ?? '')) : '',
            $annotations
        ))));

        $materializedCount = 0;
        DB::transaction(function () use ($document, $annotations, $annotationIds, &$materializedCount) {
            $sessionId = $this->extractedPdfStateSessionId($document);
            $existingRows = PdfState::query()
                ->where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->where('state', 'extracted')
                ->get()
                ->keyBy(static fn (PdfState $record) => trim((string) data_get($record->annotation_data, 'id', '')));

            $seenIds = [];
            foreach ($annotations as $annotation) {
                if (!is_array($annotation)) {
                    continue;
                }

                $annotationId = trim((string) ($annotation['id'] ?? ''));
                if ($annotationId === '') {
                    continue;
                }

                $pageIndex = isset($annotation['pageIndex']) && is_numeric($annotation['pageIndex'])
                    ? (int) $annotation['pageIndex']
                    : null;

                /** @var PdfState|null $existing */
                $existing = $existingRows->get($annotationId);
                if ($existing) {
                    $existing->update([
                        'annotation_data' => $annotation,
                        'page_number' => $pageIndex,
                    ]);
                } else {
                    PdfState::create([
                        'document_id' => $document->id,
                        'user_email' => null,
                        'session_id' => $sessionId,
                        'page_number' => $pageIndex,
                        'annotation_data' => $annotation,
                        'state' => 'extracted',
                    ]);
                }

                $seenIds[$annotationId] = true;
                $materializedCount++;
            }

            $staleIds = array_values(array_filter(
                $existingRows->keys()->all(),
                static fn ($id) => $id !== '' && !isset($seenIds[$id])
            ));

            if (!empty($staleIds)) {
                PdfState::query()
                    ->where('document_id', $document->id)
                    ->where('session_id', $sessionId)
                    ->where('state', 'extracted')
                    ->where(function ($query) use ($staleIds) {
                        foreach ($staleIds as $annotationId) {
                            $query->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.id')) = ?", [$annotationId]);
                        }
                    })
                    ->delete();
            }

            if (empty($annotationIds)) {
                PdfState::query()
                    ->where('document_id', $document->id)
                    ->where('session_id', $sessionId)
                    ->where('state', 'extracted')
                    ->delete();
            }
        });

        return $materializedCount;
    }

    private function liveSavePreviewDirectory(Document $document): string
    {
        return storage_path('app/live-save-previews/' . $document->id);
    }

    private function liveSavePreviewMetadataPath(Document $document, string $entry = 'latest'): string
    {
        $safeEntry = preg_replace('/[^A-Za-z0-9T_\-:.Z]/', '', $entry) ?: 'latest';
        return $this->liveSavePreviewDirectory($document) . '/' . $safeEntry . '.json';
    }

    private function readLiveSavePreview(Document $document, string $entry = 'latest'): ?array
    {
        $metadataPath = $this->liveSavePreviewMetadataPath($document, $entry);
        if (!is_file($metadataPath)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($metadataPath), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function listLiveSavePreviewHistory(Document $document): array
    {
        $dir = $this->liveSavePreviewDirectory($document);
        if (!is_dir($dir)) {
            return [];
        }

        $entries = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $name = basename($path);
            if ($name === 'latest.json') {
                continue;
            }

            $decoded = json_decode((string) @file_get_contents($path), true);
            if (!is_array($decoded)) {
                continue;
            }

            $entries[] = $decoded;
        }

        usort($entries, static function (array $a, array $b): int {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return $entries;
    }

    private function buildLiveSavePreviewPayload(Document $document, ?array $preview = null): array
    {
        $preview ??= $this->readLiveSavePreview($document);
        if (!$preview) {
            return [];
        }

        $entry = $preview['save_id'] ?? 'latest';

        return [
            'saved_preview_url' => route('documents.savedEdit', $document),
            'before_image_url' => route('documents.savedEditImage', ['document' => $document, 'variant' => 'before']) . '?entry=' . urlencode((string) $entry),
            'redacted_image_url' => route('documents.savedEditImage', ['document' => $document, 'variant' => 'redacted']) . '?entry=' . urlencode((string) $entry),
            'final_image_url' => route('documents.savedEditImage', ['document' => $document, 'variant' => 'final']) . '?entry=' . urlencode((string) $entry),
            'preview' => $preview,
        ];
    }

    private function syncLatestLiveSavePreview(Document $document): void
    {
        $dir = $this->liveSavePreviewDirectory($document);
        if (!is_dir($dir)) {
            return;
        }

        $history = $this->listLiveSavePreviewHistory($document);
        $latestJson = $dir . '/latest.json';
        $latestBefore = $dir . '/latest-before.png';
        $latestRedacted = $dir . '/latest-redacted.png';
        $latestFinal = $dir . '/latest-final.png';

        if (empty($history)) {
            foreach ([$latestJson, $latestBefore, $latestRedacted, $latestFinal] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            return;
        }

        $latest = $history[0];
        $saveId = preg_replace('/[^A-Za-z0-9T_\-:.Z]/', '', (string) ($latest['save_id'] ?? '')) ?: null;
        if (!$saveId) {
            return;
        }

        $sourceJson = $dir . '/' . $saveId . '.json';
        if (is_file($sourceJson)) {
            @copy($sourceJson, $latestJson);
        }

        foreach (['before', 'redacted', 'final'] as $variant) {
            $source = $dir . '/' . $saveId . '-' . $variant . '.png';
            $dest = $dir . '/latest-' . $variant . '.png';
            if (is_file($source)) {
                @copy($source, $dest);
            } elseif (is_file($dest)) {
                @unlink($dest);
            }
        }
    }

    private function discardLiveSavePreviewEntries(Document $document, array $entries): void
    {
        $dir = $this->liveSavePreviewDirectory($document);
        if (!is_dir($dir) || empty($entries)) {
            return;
        }

        $discarded = [];
        foreach ($entries as $entry) {
            $saveId = preg_replace('/[^A-Za-z0-9T_\-:.Z]/', '', (string) $entry) ?: null;
            if (!$saveId || isset($discarded[$saveId])) {
                continue;
            }
            $discarded[$saveId] = true;

            foreach ([
                $dir . '/' . $saveId . '.json',
                $dir . '/' . $saveId . '-before.png',
                $dir . '/' . $saveId . '-redacted.png',
                $dir . '/' . $saveId . '-final.png',
            ] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        if (!empty($discarded)) {
            $this->syncLatestLiveSavePreview($document);
        }
    }

    private function liveSaveSnapshotDirectory(Document $document): string
    {
        return storage_path('app/live-save-working-copies/' . $document->id);
    }

    private function liveSaveSnapshotPath(Document $document, string $token): string
    {
        $safeToken = preg_replace('/[^A-Za-z0-9_\-]/', '', $token) ?: 'invalid';
        return $this->liveSaveSnapshotDirectory($document) . '/' . $safeToken . '.pdf';
    }

    private function pruneLiveSaveSnapshots(Document $document, int $maxAgeSeconds = 21600): void
    {
        $dir = $this->liveSaveSnapshotDirectory($document);
        if (!is_dir($dir)) {
            return;
        }

        $cutoff = time() - max(60, $maxAgeSeconds);
        foreach (glob($dir . '/*.pdf') ?: [] as $path) {
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($path);
            }
        }
    }

    private function createLiveSaveSnapshot(Document $document): array
    {
        $sourcePath = Storage::path($document->path);
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('Document PDF not found.');
        }

        $dir = $this->liveSaveSnapshotDirectory($document);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create working-copy snapshot directory.');
        }

        $this->pruneLiveSaveSnapshots($document);

        $token = now()->format('YmdHisv') . '_' . Str::random(12);
        $snapshotPath = $this->liveSaveSnapshotPath($document, $token);
        if (!@copy($sourcePath, $snapshotPath)) {
            throw new \RuntimeException('Failed to create working-copy snapshot.');
        }

        return [
            'token' => $token,
            'path' => $snapshotPath,
        ];
    }

    private function invalidateCleanPdf(Document $document): void
    {
        $cleanPath = Storage::path('temp/clean_' . $document->id . '.pdf');
        if (file_exists($cleanPath)) {
            @unlink($cleanPath);
        }
    }

    private function createCleanPdfFromExtractionSource(
        Document $document,
        string $pythonBinary,
        string $sourcePdfPath,
        ?string $userEmail = null,
        ?string $sessionId = null
    ): ?string {
        $latestExtraction = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);
        if (!$latestExtraction || !isset($latestExtraction->extraction_data)) {
            return null;
        }

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $extractionFile = tempnam(sys_get_temp_dir(), 'tb_clean_src_');
        if ($extractionFile === false) {
            $extractionFile = $tempDir . '/clean_source_extraction_' . $document->id . '_' . Str::uuid() . '.json';
        }

        $cleanPath = $tempDir . '/load_saved_pdf_clean_' . $document->id . '_' . Str::uuid() . '.pdf';

        try {
            $extractionJson = is_string($latestExtraction->extraction_data)
                ? $latestExtraction->extraction_data
                : json_encode($latestExtraction->extraction_data, JSON_INVALID_UTF8_SUBSTITUTE);

            if (!is_string($extractionJson) || @file_put_contents($extractionFile, $extractionJson) === false) {
                return null;
            }

            $pythonScript = base_path('python/pdf-editor/create_clean_pdf.py');
            $command = sprintf(
                '%s %s %s %s %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($pythonScript),
                escapeshellarg($sourcePdfPath),
                escapeshellarg($extractionFile),
                escapeshellarg($cleanPath)
            );

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($cleanPath)) {
                \Log::warning('Failed to create clean PDF from extraction source', [
                    'document_id' => $document->id,
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output),
                ]);
                if (file_exists($cleanPath)) {
                    @unlink($cleanPath);
                }
                return null;
            }

            return $cleanPath;
        } finally {
            if (is_string($extractionFile) && file_exists($extractionFile)) {
                @unlink($extractionFile);
            }
        }
    }

    private function ensureCleanPdfPath(
        Document $document,
        string $pythonBinary,
        ?string $userEmail = null,
        ?string $sessionId = null
    ): ?string {
        $cleanPath = Storage::path('temp/clean_' . $document->id . '.pdf');
        if (is_file($cleanPath)) {
            return $cleanPath;
        }

        $sourcePdfPath = Storage::path($document->path);
        if ($document->original_backup_path && Storage::exists($document->original_backup_path)) {
            $originalBackupPath = Storage::path($document->original_backup_path);
            if (is_file($originalBackupPath)) {
                $sourcePdfPath = $originalBackupPath;
            }
        }

        if (!is_file($sourcePdfPath)) {
            return null;
        }

        $generatedCleanPath = $this->createCleanPdfFromExtractionSource(
            $document,
            $pythonBinary,
            $sourcePdfPath,
            $userEmail,
            $sessionId
        );

        if (!$generatedCleanPath || !is_file($generatedCleanPath)) {
            return null;
        }

        $cleanDir = dirname($cleanPath);
        if (!is_dir($cleanDir)) {
            @mkdir($cleanDir, 0775, true);
        }

        $stored = @copy($generatedCleanPath, $cleanPath);
        @unlink($generatedCleanPath);

        if (!$stored || !is_file($cleanPath)) {
            if (is_file($cleanPath)) {
                @unlink($cleanPath);
            }
            return null;
        }

        return $cleanPath;
    }

    private function mergeDeletedPromotedSourceKeys(
        Document $document,
        string $sessionId,
        array $requestedKeys = [],
        array $annotationsPayload = []
    ): array {
        $mergedKeys = [];

        foreach ($requestedKeys as $key) {
            if (!is_string($key)) {
                continue;
            }
            $normalized = trim($key);
            if ($normalized !== '') {
                $mergedKeys[$normalized] = true;
            }
        }

        if ($sessionId !== '') {
            PdfState::query()
                ->where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->where('state', 'deleted')
                ->get(['annotation_data'])
                ->each(static function (PdfState $record) use (&$mergedKeys) {
                    $sourceKey = trim((string) data_get($record->annotation_data, 'promotedSourceKey', ''));
                    $explicitDelete = data_get($record->annotation_data, '_explicitPromotedDelete');
                    if ($sourceKey !== '' && filter_var($explicitDelete, FILTER_VALIDATE_BOOLEAN)) {
                        $mergedKeys[$sourceKey] = true;
                    }
                });
        }

        foreach ($annotationsPayload as $annotation) {
            if (!is_array($annotation)) {
                continue;
            }

            $activeSourceKey = trim((string) ($annotation['promotedSourceKey'] ?? ''));
            if ($activeSourceKey !== '') {
                unset($mergedKeys[$activeSourceKey]);
            }
        }

        $result = array_keys($mergedKeys);
        sort($result);

        return $result;
    }

    private function parsePromotedSourceKeyPageIndex(string $sourceKey): ?int
    {
        if (!preg_match('/^block-(\d+)-\d+(?:-.+)?$/', trim($sourceKey), $matches)) {
            return null;
        }

        $pageNumber = (int) ($matches[1] ?? 0);
        return $pageNumber > 0 ? ($pageNumber - 1) : null;
    }

    private function annotationCanBeDirectStamped(array $annotation): bool
    {
        // Disabled by policy: dirty promoted extraction text must always trigger
        // full page redraw instead of direct stamping.
        return false;
    }

    private function normalizeComparableAnnotationNumberForSelectiveRedraw($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }

    private function getCanonicalPromotedAnnotationSourceTextForSelectiveRedraw(array $annotation): string
    {
        $sourceTextLines = array_values(array_filter(array_map(
            fn ($line) => $this->sanitizePromotedExtractionLineForMaterialization($line),
            is_array($annotation['sourceTextLines'] ?? null) ? $annotation['sourceTextLines'] : []
        ), static fn ($line) => $line !== ''));

        if (!empty($sourceTextLines)) {
            return $this->sanitizePromotedExtractionTextForMaterialization(implode("\n", $sourceTextLines));
        }

        return $this->sanitizePromotedExtractionTextForMaterialization($annotation['originalText'] ?? '');
    }

    private function getPromotedAnnotationPrimarySourceSpanForSelectiveRedraw(array $annotation): array
    {
        foreach ((is_array($annotation['sourceSpans'] ?? null) ? $annotation['sourceSpans'] : []) as $span) {
            if (is_array($span)) {
                return $span;
            }
        }

        return [];
    }

    private function getPromotedAnnotationSourceFontFamilyForSelectiveRedraw(array $annotation): string
    {
        $span = $this->getPromotedAnnotationPrimarySourceSpanForSelectiveRedraw($annotation);
        $fontSourceName = trim((string) ($span['font'] ?? ''));
        $resolvedFontFamily = $this->normalizePdfEditableFontFamilyForMaterialization($fontSourceName);
        if ($resolvedFontFamily !== '') {
            return $resolvedFontFamily;
        }

        return $this->normalizeBuiltinAnnotationFontFamilyForMaterialization($fontSourceName ?: 'Helvetica');
    }

    private function getPromotedAnnotationSourceFontStyleForSelectiveRedraw(array $annotation): string
    {
        $span = $this->getPromotedAnnotationPrimarySourceSpanForSelectiveRedraw($annotation);
        $fontName = trim((string) ($span['font'] ?? ''));
        if (!empty($span['italic']) || preg_match('/italic|oblique/i', $fontName)) {
            return 'italic';
        }

        return 'normal';
    }

    private function getPromotedAnnotationSourceFontWeightForSelectiveRedraw(array $annotation): string
    {
        $span = $this->getPromotedAnnotationPrimarySourceSpanForSelectiveRedraw($annotation);
        $fontWeight = trim((string) ($span['font_weight'] ?? ''));
        if ($fontWeight !== '') {
            return $fontWeight;
        }

        $fontName = trim((string) ($span['font'] ?? ''));
        return (!empty($span['bold']) || preg_match('/bold/i', $fontName)) ? '700' : '400';
    }

    private function getPromotedAnnotationSourceColorForSelectiveRedraw(array $annotation): string
    {
        $span = $this->getPromotedAnnotationPrimarySourceSpanForSelectiveRedraw($annotation);
        if (!empty($span['hex_color'])) {
            return $this->colorToHexForMaterialization((string) $span['hex_color']);
        }

        if (array_key_exists('color', $span) && $span['color'] !== null) {
            if (is_numeric($span['color'])) {
                return $this->colorToHexForMaterialization('#' . str_pad(dechex((int) $span['color']), 6, '0', STR_PAD_LEFT));
            }

            return $this->colorToHexForMaterialization((string) $span['color']);
        }

        return '#000000';
    }

    private function promotedAnnotationMatchesExactSourceStateForSelectiveRedraw(array $annotation): bool
    {
        if (!($annotation['promotedFromExtraction'] ?? false) || !empty($annotation['promotedReflowEnabled'])) {
            return false;
        }

        $currentText = $this->sanitizePromotedExtractionTextForMaterialization($annotation['text'] ?? '');
        if ($currentText !== $this->getCanonicalPromotedAnnotationSourceTextForSelectiveRedraw($annotation)) {
            return false;
        }

        if (trim((string) ($annotation['richTextHtml'] ?? '')) !== '') {
            return false;
        }

        $sourceLineBBoxes = array_values(array_filter(
            is_array($annotation['sourceLineBBoxes'] ?? null) ? $annotation['sourceLineBBoxes'] : [],
            static fn ($bbox) => is_array($bbox) && count($bbox) >= 4
        ));
        if (empty($sourceLineBBoxes)) {
            return false;
        }

        $sourceBlockLeft = isset($annotation['sourceBlockLeft']) ? (float) $annotation['sourceBlockLeft'] : null;
        $sourceBlockTop = isset($annotation['sourceBlockTop']) ? (float) $annotation['sourceBlockTop'] : null;
        $sourceBlockWidth = isset($annotation['sourceBlockWidth']) ? (float) $annotation['sourceBlockWidth'] : null;
        $sourceBlockHeight = isset($annotation['sourceBlockHeight']) ? (float) $annotation['sourceBlockHeight'] : null;
        $sourcePageHeight = isset($annotation['sourcePageHeight']) ? (float) $annotation['sourcePageHeight'] : null;
        if ($sourceBlockLeft === null || $sourceBlockTop === null || $sourceBlockWidth === null || $sourceBlockHeight === null || !($sourcePageHeight > 0)) {
            return false;
        }

        $currentTop = $sourcePageHeight - (((float) ($annotation['pdfY'] ?? 0)) + ((float) ($annotation['pdfHeight'] ?? 0)));
        $geometryTolerance = 0.75;
        if (
            abs(((float) ($annotation['pdfX'] ?? 0)) - $sourceBlockLeft) > $geometryTolerance
            || abs($currentTop - $sourceBlockTop) > $geometryTolerance
            || abs(((float) ($annotation['pdfWidth'] ?? 0)) - $sourceBlockWidth) > $geometryTolerance
            || abs(((float) ($annotation['pdfHeight'] ?? 0)) - $sourceBlockHeight) > $geometryTolerance
        ) {
            return false;
        }

        if (strtolower((string) ($annotation['backgroundColor'] ?? 'transparent')) !== 'transparent') {
            return false;
        }

        if ($this->colorToHexForMaterialization((string) ($annotation['textColor'] ?? '#000000')) !== $this->getPromotedAnnotationSourceColorForSelectiveRedraw($annotation)) {
            return false;
        }

        if (trim((string) ($annotation['fontFamily'] ?? '')) !== $this->getPromotedAnnotationSourceFontFamilyForSelectiveRedraw($annotation)) {
            return false;
        }

        if (trim((string) ($annotation['fontStyle'] ?? 'normal')) !== $this->getPromotedAnnotationSourceFontStyleForSelectiveRedraw($annotation)) {
            return false;
        }

        if (trim((string) ($annotation['fontWeight'] ?? '400')) !== $this->getPromotedAnnotationSourceFontWeightForSelectiveRedraw($annotation)) {
            return false;
        }

        if (!empty($annotation['underline'])) {
            return false;
        }

        if (trim((string) ($annotation['textAlign'] ?? 'left')) !== 'left') {
            return false;
        }

        if ($this->normalizeComparableAnnotationNumberForSelectiveRedraw($annotation['opacity'] ?? 1) !== 1.0) {
            return false;
        }

        $sourceLineHeights = array_values(array_filter(array_map(static function (array $bbox): float {
            return (float) ($bbox[3] ?? 0) - (float) ($bbox[1] ?? 0);
        }, $sourceLineBBoxes), static fn ($height) => is_finite($height) && $height > 0));
        $savedLineHeight = (float) ($annotation['lineHeight'] ?? 0);
        if (!empty($sourceLineHeights) && $savedLineHeight > 0) {
            $averageSourceLineHeight = array_sum($sourceLineHeights) / count($sourceLineHeights);
            if (abs($savedLineHeight - $averageSourceLineHeight) > 1.5) {
                return false;
            }
        }

        return true;
    }

    private function annotationRequiresSelectiveRedraw(array $annotation): bool
    {
        if ($this->isFieldAnnotation($annotation)) {
            return false;
        }

        if (!($annotation['promotedFromExtraction'] ?? false)) {
            return true;
        }

        return !$this->promotedAnnotationMatchesExactSourceStateForSelectiveRedraw($annotation);
    }

    private function isFieldAnnotation(array $annotation): bool
    {
        return strtolower((string) ($annotation['type'] ?? '')) === 'field';
    }

    private function shouldReplayAnnotationOnSelectiveRedraw(array $annotation): bool
    {
        if ($this->isFieldAnnotation($annotation)) {
            return false;
        }

        if (!($annotation['promotedFromExtraction'] ?? false)) {
            return true;
        }

        return true;
    }

    private function filterRenderAnnotationsForSelectiveRedraw(
        array $annotationsPayload,
        array $requestedPageIndices
    ): array {
        $allowedPages = array_fill_keys(array_values(array_unique(array_filter(array_map(
            static fn ($value) => is_numeric($value) ? (int) $value : null,
            $requestedPageIndices
        ), static fn ($value) => is_int($value) && $value >= 0))), true);

        if (empty($allowedPages)) {
            return [];
        }

        return array_values(array_filter($annotationsPayload, function ($annotation) use ($allowedPages) {
            if (!is_array($annotation)) {
                return false;
            }

            $pageIndex = isset($annotation['pageIndex']) && is_numeric($annotation['pageIndex'])
                ? (int) $annotation['pageIndex']
                : null;
            if ($pageIndex === null || !isset($allowedPages[$pageIndex])) {
                return false;
            }

            return $this->shouldReplayAnnotationOnSelectiveRedraw($annotation);
        }));
    }

    private function filterSelectiveRedrawPageIndices(
        array $requestedPageIndices,
        array $annotationsPayload,
        array $deletedPromotedSourceKeys = []
    ): array {
        $normalizedRequested = array_values(array_unique(array_filter(array_map(static function ($value) {
            return is_numeric($value) ? (int) $value : null;
        }, $requestedPageIndices), static fn ($value) => is_int($value) && $value >= 0)));

        $requiredPages = [];
        foreach ($annotationsPayload as $annotation) {
            if (!is_array($annotation) || !$this->annotationRequiresSelectiveRedraw($annotation)) {
                continue;
            }
            $pageIndex = isset($annotation['pageIndex']) && is_numeric($annotation['pageIndex'])
                ? (int) $annotation['pageIndex']
                : null;
            if ($pageIndex !== null && $pageIndex >= 0) {
                $requiredPages[$pageIndex] = true;
            }
        }

        foreach ($deletedPromotedSourceKeys as $sourceKey) {
            if (!is_string($sourceKey)) {
                continue;
            }
            $pageIndex = $this->parsePromotedSourceKeyPageIndex($sourceKey);
            if ($pageIndex !== null && $pageIndex >= 0) {
                $requiredPages[$pageIndex] = true;
            }
        }

        if (empty($normalizedRequested)) {
            if (empty($requiredPages)) {
                return [];
            }
            $result = array_keys($requiredPages);
            sort($result);
            return $result;
        }

        // Honor any client-requested redraw pages. The browser already computed
        // the current changed-page set, and filtering it back down here can drop
        // saved-session edits that must be rebuilt from the clean page base.
        $result = $normalizedRequested;

        foreach (array_keys($requiredPages) as $pageIndex) {
            if (!in_array($pageIndex, $result, true)) {
                $result[] = $pageIndex;
            }
        }

        sort($result);
        return $result;
    }

    private function mergeSelectiveRedrawPageIndices(
        array $requestedPageIndices,
        array $annotationsPayload,
        array $renderAnnotationsPayload,
        array $deletedPromotedSourceKeys = []
    ): array {
        $mergedRequested = array_values(array_unique(array_merge(
            $requestedPageIndices,
            $this->collectAnnotationPageIndices($renderAnnotationsPayload)
        )));

        return $this->filterSelectiveRedrawPageIndices(
            $mergedRequested,
            array_merge($annotationsPayload, $renderAnnotationsPayload),
            $deletedPromotedSourceKeys
        );
    }

    private function collectAnnotationPageIndices(array $annotationsPayload): array
    {
        $pageIndices = [];

        foreach ($annotationsPayload as $annotation) {
            if (!is_array($annotation)) {
                continue;
            }
            $pageIndex = isset($annotation['pageIndex']) && is_numeric($annotation['pageIndex'])
                ? (int) $annotation['pageIndex']
                : null;
            if ($pageIndex !== null && $pageIndex >= 0) {
                $pageIndices[$pageIndex] = true;
            }
        }

        $result = array_keys($pageIndices);
        sort($result);

        return $result;
    }

    private function runSelectiveAnnotationPageRedraw(
        Document $document,
        string $pythonBinary,
        string $outputPdfPath,
        string $preservePdfPath,
        array $renderAnnotationsPayload,
        array $redrawPageIndices,
        array $deletedPromotedSourceKeys = [],
        ?string $userEmail = null,
        ?string $sessionId = null
    ): array {
        $normalizedPageIndices = array_values(array_unique(array_filter(array_map(static function ($value) {
            return is_numeric($value) ? (int) $value : null;
        }, $redrawPageIndices), static fn ($value) => is_int($value) && $value >= 0)));
        sort($normalizedPageIndices);

        foreach ($deletedPromotedSourceKeys as $sourceKey) {
            if (!is_string($sourceKey)) {
                continue;
            }
            $pageIndex = $this->parsePromotedSourceKeyPageIndex($sourceKey);
            if ($pageIndex === null) {
                continue;
            }
            if (!in_array($pageIndex, $normalizedPageIndices, true)) {
                $normalizedPageIndices[] = $pageIndex;
            }
        }
        sort($normalizedPageIndices);

        if (empty($normalizedPageIndices)) {
            return [
                'success' => true,
                'mode' => 'skipped',
                'message' => 'No pages required redraw.',
            ];
        }

        $cleanSourcePath = $preservePdfPath;
        if ($document->original_backup_path && Storage::exists($document->original_backup_path)) {
            $candidate = Storage::path($document->original_backup_path);
            if (is_file($candidate)) {
                $cleanSourcePath = $candidate;
            }
        }

        $cleanPdfPath = $this->createCleanPdfFromExtractionSource(
            $document,
            $pythonBinary,
            $cleanSourcePath,
            $userEmail,
            $sessionId
        );

        if (!$cleanPdfPath || !is_file($cleanPdfPath)) {
            return [
                'success' => false,
                'message' => 'Failed to prepare clean PDF base for selective page redraw.',
            ];
        }

        $extractionRow = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);
        if (!$extractionRow || !isset($extractionRow->extraction_data)) {
            @unlink($cleanPdfPath);
            return [
                'success' => false,
                'message' => 'No extraction data found for selective page redraw.',
            ];
        }

        $tempJsonDir = storage_path('app/temp');
        if (!is_dir($tempJsonDir)) {
            @mkdir($tempJsonDir, 0775, true);
        }
        $makeTempFile = function (string $prefix) use ($tempJsonDir, $document) {
            $candidates = [$tempJsonDir, sys_get_temp_dir()];
            foreach ($candidates as $dir) {
                if (!$dir || !is_dir($dir) || !is_writable($dir)) {
                    continue;
                }
                $path = rtrim($dir, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR
                    . $prefix
                    . $document->id
                    . '_'
                    . uniqid('', true)
                    . '.json';
                if (@file_put_contents($path, '') !== false) {
                    return $path;
                }
            }
            throw new \RuntimeException('Failed to allocate temporary JSON file path.');
        };

        $cleanupFiles = [];

        try {
            $extractionFile = $makeTempFile('redraw_extraction_');
            $pagesFile = $makeTempFile('redraw_pages_');
            $renderAnnotationsFile = $makeTempFile('redraw_annotations_');
            $deletedKeysFile = $makeTempFile('redraw_deleted_keys_');
            $cleanupFiles = [$extractionFile, $pagesFile, $renderAnnotationsFile, $deletedKeysFile, $cleanPdfPath];

            $renderAnnotationsPayload = array_map(function ($annotation) use ($document) {
                if (!is_array($annotation)) {
                    return $annotation;
                }
                $annotation['__documentId'] = $document->id;
                return $annotation;
            }, $renderAnnotationsPayload);

            $renderAnnotationsJson = json_encode(
                $this->prepareAnnotationsForPython($renderAnnotationsPayload),
                JSON_INVALID_UTF8_SUBSTITUTE
            );
            $deletedKeysJson = json_encode(array_values(array_filter($deletedPromotedSourceKeys, 'is_string')), JSON_INVALID_UTF8_SUBSTITUTE);
            $pagesJson = json_encode($normalizedPageIndices, JSON_INVALID_UTF8_SUBSTITUTE);
            $extractionJson = is_string($extractionRow->extraction_data)
                ? $extractionRow->extraction_data
                : json_encode($extractionRow->extraction_data, JSON_INVALID_UTF8_SUBSTITUTE);

            if (
                !is_string($extractionJson)
                || $renderAnnotationsJson === false
                || $deletedKeysJson === false
                || $pagesJson === false
                || @file_put_contents($extractionFile, $extractionJson) === false
                || @file_put_contents($pagesFile, $pagesJson) === false
                || @file_put_contents($renderAnnotationsFile, $renderAnnotationsJson) === false
                || @file_put_contents($deletedKeysFile, $deletedKeysJson) === false
            ) {
                return [
                    'success' => false,
                    'message' => 'Failed to prepare selective redraw payload.',
                ];
            }

            $script = base_path('python/pdf-editor/apply_annotations_redraw_pages.py');
            $command = sprintf(
                '%s %s %s %s %s %s %s %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($script),
                escapeshellarg($cleanPdfPath),
                escapeshellarg('@' . $extractionFile),
                escapeshellarg('@' . $renderAnnotationsFile),
                escapeshellarg($outputPdfPath),
                escapeshellarg('@' . $pagesFile),
                escapeshellarg($preservePdfPath)
            );

            if (!empty($deletedPromotedSourceKeys)) {
                $command .= ' ' . escapeshellarg('@' . $deletedKeysFile);
            }

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                \Log::error('Selective annotation page redraw failed', [
                    'document_id' => $document->id,
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output),
                    'redraw_pages' => $normalizedPageIndices,
                ]);

                return [
                    'success' => false,
                    'message' => 'Selective page redraw failed.',
                    'error' => implode("\n", $output),
                ];
            }

            return [
                'success' => true,
                'mode' => 'selective_redraw',
                'redraw_pages' => $normalizedPageIndices,
            ];
        } finally {
            foreach ($cleanupFiles as $path) {
                if (is_string($path) && file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function refreshOverlayExtractionArtifacts(Document $document, string $pythonBinary, bool $skipRefresh = false): void
    {
        if ($skipRefresh) {
            return;
        }

        $fullPath = Storage::path($document->path);
        $extractScript = base_path('python/pdf-editor/extract_pdf_pymupdf.py');
        $userEmail = $this->resolveEditorEmail();
        $sessionId = session()->getId();
        $refreshCommand = sprintf(
            '%s %s %s %d %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($extractScript),
            escapeshellarg($fullPath),
            $document->id,
            escapeshellarg($userEmail),
            escapeshellarg($sessionId)
        );

        $refreshOutput = [];
        $refreshCode = 0;
        exec($refreshCommand, $refreshOutput, $refreshCode);
        \Log::info('Refreshed extraction data', [
            'document_id' => $document->id,
            'return_code' => $refreshCode,
            'output' => implode("\n", $refreshOutput),
        ]);

        $cleanPath = Storage::path('temp/clean_' . $document->id . '.pdf');
        $latestExtraction = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);
        if (!$latestExtraction) {
            return;
        }

        $extractionFile = tempnam(sys_get_temp_dir(), 'extract_post_');
        if ($extractionFile === false) {
            throw new \RuntimeException('Failed to create post-save extraction temp file.');
        }

        try {
            $extractionData = is_string($latestExtraction->extraction_data)
                ? $latestExtraction->extraction_data
                : json_encode($latestExtraction->extraction_data);

            if (@file_put_contents($extractionFile, $extractionData) === false) {
                throw new \RuntimeException('Failed to write post-save extraction temp file.');
            }

            $pythonScript = base_path('python/pdf-editor/create_clean_pdf.py');
            $cleanCommand = sprintf(
                '%s %s %s %s %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($pythonScript),
                escapeshellarg($fullPath),
                escapeshellarg($extractionFile),
                escapeshellarg($cleanPath)
            );

            $cleanOutput = [];
            $cleanCode = 0;
            exec($cleanCommand, $cleanOutput, $cleanCode);

            \Log::info('Regenerated clean PDF after save', [
                'document_id' => $document->id,
                'return_code' => $cleanCode,
                'clean_exists' => file_exists($cleanPath),
            ]);
        } finally {
            if (file_exists($extractionFile)) {
                @unlink($extractionFile);
            }
        }
    }

    private function runLiveSaveScript(Document $document, array $edit, ?string $workingCopyToken = null): array
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
        $pdfPath = $workingCopyToken
            ? $this->liveSaveSnapshotPath($document, $workingCopyToken)
            : Storage::path($document->path);

        if (!file_exists($pdfPath)) {
            return [
                'success' => false,
                'error' => 'PDF file not found.',
                'status' => 404,
            ];
        }

        $editFile = tempnam(sys_get_temp_dir(), 'tb_ls_');
        if ($editFile === false) {
            return [
                'success' => false,
                'error' => 'Could not create temp file.',
                'status' => 500,
            ];
        }

        $previewDir = $this->liveSavePreviewDirectory($document);
        if (!is_dir($previewDir) && !@mkdir($previewDir, 0755, true) && !is_dir($previewDir)) {
            @unlink($editFile);
            return [
                'success' => false,
                'error' => 'Could not create preview directory.',
                'status' => 500,
            ];
        }

        try {
            file_put_contents($editFile, json_encode($edit, JSON_UNESCAPED_UNICODE));

            $script = base_path('python/pdf-editor/live_save.py');
            $command = sprintf(
                '%s %s %s %s %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($script),
                escapeshellarg($pdfPath),
                escapeshellarg($editFile),
                escapeshellarg($previewDir)
            );

            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);
            $outputStr = implode("\n", $output);

            $jsonLine = '';
            foreach (array_reverse($output) as $line) {
                $line = trim($line);
                if (str_starts_with($line, '{')) {
                    $jsonLine = $line;
                    break;
                }
            }

            $result = $jsonLine ? json_decode($jsonLine, true) : null;
            if ($exitCode !== 0 || !$result || !($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? ($outputStr ?: 'Live save failed.'),
                    'status' => 500,
                    'output' => $outputStr,
                    'exit_code' => $exitCode,
                ];
            }

            $this->invalidateCleanPdf($document);
            $previewPayload = $this->buildLiveSavePreviewPayload($document);

            return [
                'success' => true,
                'result' => $result,
                'status' => 200,
                'output' => $outputStr,
                'exit_code' => $exitCode,
                'python_binary' => $pythonBinary,
                'preview' => $previewPayload,
            ];
        } finally {
            if (file_exists($editFile)) {
                @unlink($editFile);
            }
        }
    }

    public function index()
    {
        $documents = Document::latest()->get();
        $guidedTemplates = GuidedTemplate::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $guidedTemplatesByType = $guidedTemplates->groupBy('type');

        return view('documents.index', [
            'documents' => $documents,
            'guidedTemplates' => $guidedTemplates,
            'guidedTemplatesByType' => $guidedTemplatesByType,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        if ($response = $this->consumeMonthlyUploadQuota($request)) {
            return $response;
        }

        $file = $validated['document'];
        $storedPath = $file->storeAs(
            'documents',
            Str::uuid()->toString() . '.pdf'
        );
        $backupPath = $this->createOriginalBackup($storedPath);

        $document = Document::create([
            'user_id' => Auth::id(),
            'original_name' => $file->getClientOriginalName(),
            'path' => $storedPath,
            'original_backup_path' => $backupPath,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        // Auto-download fonts for this PDF in background
        $fullPath = Storage::path($storedPath);
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
        $fontScript = base_path('python/pdf-editor/auto_download_fonts.py');
        $fontCommand = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($pythonBinary),
            escapeshellarg($fontScript),
            escapeshellarg($fullPath)
        );
        exec($fontCommand);

        $userEmail = $this->resolveEditorEmail();
        $sessionId = $request->session()->getId();
        $materializedAcroFormCount = $this->ensurePdfAcroFormMaterialized($document, $fullPath, $pythonBinary);
        [$extractionReturnCode, $extractionOutput] = $this->runFitzExtraction(
            $document,
            $fullPath,
            $userEmail,
            $sessionId,
            $pythonBinary
        );

        if ($extractionReturnCode === 0) {
            $latestExtraction = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);
            if ($latestExtraction) {
                $materializedCount = $this->materializeFitzExtractionToPdfState($document, $latestExtraction);
                Log::info('Materialized upload extraction into pdf_state', [
                    'document_id' => $document->id,
                    'materialized_count' => $materializedCount,
                ]);
            } else {
                Log::warning('Fitz extraction completed but no extraction row was found for upload materialization', [
                    'document_id' => $document->id,
                    'user_email' => $userEmail,
                    'session_id' => $sessionId,
                ]);
            }
        } else {
            Log::warning('Upload-time Fitz extraction failed; overlay prep will retry', [
                'document_id' => $document->id,
                'python_binary' => $pythonBinary,
                'return_code' => $extractionReturnCode,
                'output' => implode("\n", $extractionOutput),
            ]);
        }

        Log::info('Materialized upload AcroForm rows', [
            'document_id' => $document->id,
            'materialized_count' => $materializedAcroFormCount,
        ]);

        return redirect()
            ->route('documents.edit', $document)
            ->with('status', 'PDF uploaded. You can edit it below.');
    }

    public function createBlank(Request $request)
    {
        $validated = $request->validate([
            'page_size' => ['nullable', 'string', 'in:A4,Letter,Legal,A3,A5'],
            'orientation' => ['nullable', 'string', 'in:portrait,landscape'],
        ]);

        if ($response = $this->consumeMonthlyUploadQuota($request)) {
            return $response;
        }

        $pageSize = $validated['page_size'] ?? 'A4';
        $orientation = $validated['orientation'] ?? 'portrait';

        // Page dimensions in points (72 pt = 1 inch)
        $sizes = [
            'A4'     => [595.28, 841.89],
            'Letter' => [612.00, 792.00],
            'Legal'  => [612.00, 1008.00],
            'A3'     => [841.89, 1190.55],
            'A5'     => [419.53, 595.28],
        ];

        [$width, $height] = $sizes[$pageSize];
        if ($orientation === 'landscape') {
            [$width, $height] = [$height, $width];
        }

        $uuid = Str::uuid()->toString();
        $storedRelative = 'documents/' . $uuid . '.pdf';
        $storedFull = Storage::path($storedRelative);

        Storage::makeDirectory('documents');

        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        // Write the Python code to a temp file to avoid shell quoting issues.
        $scriptCode = implode("\n", [
            'import fitz, sys',
            'doc = fitz.open()',
            sprintf('doc.new_page(width=%s, height=%s)', (float) $width, (float) $height),
            sprintf('doc.save(%s)', var_export($storedFull, true)),
            'doc.close()',
        ]);
        $tmpScript = tempnam(sys_get_temp_dir(), 'blank_pdf_') . '.py';
        file_put_contents($tmpScript, $scriptCode);

        $output = [];
        $exitCode = 0;
        exec(sprintf('%s %s 2>&1', escapeshellarg($pythonBinary), escapeshellarg($tmpScript)), $output, $exitCode);
        @unlink($tmpScript);

        if ($exitCode !== 0 || !file_exists($storedFull)) {
            Log::error('Blank PDF creation failed', [
                'output' => implode("\n", $output),
                'exit_code' => $exitCode,
            ]);
            return redirect()
                ->route('documents.index')
                ->withErrors('Failed to create blank PDF. Please try again.');
        }

        $document = Document::create([
            'user_id' => Auth::id(),
            'original_name' => 'Blank ' . $pageSize . ' ' . ucfirst($orientation) . '.pdf',
            'path' => $storedRelative,
            'original_backup_path' => $this->createOriginalBackup($storedRelative),
            'mime_type' => 'application/pdf',
            'size_bytes' => filesize($storedFull),
        ]);

        return redirect()
            ->route('documents.edit', $document)
            ->with('status', 'Blank PDF created. You can now add text, images and annotations.');
    }

    public function createFromTemplate(Request $request)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'template' => ['required', 'string', 'in:clean_modern,bold_red,classic_blue'],
        ]);

        $templateNames = [
            'clean_modern' => 'Invoice - Clean Modern.pdf',
            'bold_red'     => 'Invoice - Bold Red.pdf',
            'classic_blue' => 'Invoice - Classic Blue.pdf',
        ];

        $templateKey = $validated['template'];
        $uuid = Str::uuid()->toString();
        $storedRelative = 'documents/' . $uuid . '.pdf';
        $storedFull = Storage::path($storedRelative);

        // Ensure directory exists
        Storage::makeDirectory('documents');

        $script = base_path('python/pdf-editor/generate_invoice_template.py');
        $command = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($templateKey),
            escapeshellarg($storedFull)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($storedFull)) {
            Log::error('Template generation failed', [
                'template' => $templateKey,
                'output' => implode("\n", $output),
                'exit_code' => $exitCode,
            ]);
            return redirect()
                ->route('documents.index')
                ->withErrors('Failed to generate template. Please try again.');
        }

        $document = Document::create([
            'user_id' => Auth::id(),
            'original_name' => $templateNames[$templateKey],
            'path' => $storedRelative,
            'original_backup_path' => $this->createOriginalBackup($storedRelative),
            'mime_type' => 'application/pdf',
            'size_bytes' => filesize($storedFull),
        ]);

        // Auto-download fonts
        $fontScript = base_path('python/pdf-editor/auto_download_fonts.py');
        $fontCommand = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($pythonBinary),
            escapeshellarg($fontScript),
            escapeshellarg($storedFull)
        );
        exec($fontCommand);

        return redirect()
            ->route('documents.edit', $document)
            ->with('status', 'Template created. Customize it below.');
    }

    public function createSimpleInvoice(Request $request)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'company_name'     => ['nullable', 'string', 'max:200'],
            'company_address'  => ['nullable', 'string', 'max:500'],
            'customer_name'    => ['nullable', 'string', 'max:200'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'invoice_number'   => ['nullable', 'string', 'max:50'],
            'invoice_date'     => ['nullable', 'string', 'max:30'],
            'due_date'         => ['nullable', 'string', 'max:30'],
            'items'            => ['nullable', 'array'],
            'items.*.qty'         => ['nullable', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string', 'max:200'],
            'items.*.unit_price'  => ['nullable', 'numeric', 'min:0'],
            'discount_label'   => ['nullable', 'string', 'max:100'],
            'discount_amount'  => ['nullable', 'numeric', 'min:0'],
            'terms'            => ['nullable', 'string', 'max:2000'],
            'style'            => ['nullable', 'string', 'in:default,bold_red'],
        ]);

        $style = $validated['style'] ?? 'default';

        // Enforce one template per type: redirect to existing if found
        $existing = Document::where('mode', 'guided')
            ->where('template_type', 'invoice')
            ->where('template_slug', $style)
            ->first();

        if ($existing) {
            $editUrl = route('documents.guided', $existing);
            if ($style !== 'default') {
                $editUrl .= '?style=' . urlencode($style);
            }
            return redirect($editUrl)
                ->with('status', 'You already have this invoice template. Editing existing one.');
        }

        $uuid = Str::uuid()->toString();
        $storedRelative = 'documents/' . $uuid . '.pdf';
        $storedFull = Storage::path($storedRelative);

        Storage::makeDirectory('documents');

        // Build JSON payload for the Python script
        $payload = json_encode($validated, JSON_UNESCAPED_UNICODE);

        // Write payload to temp file to avoid shell escaping issues with newlines
        $tmpPayload = tempnam(sys_get_temp_dir(), 'inv_');
        file_put_contents($tmpPayload, $payload);

        $script = base_path('python/pdf-editor/generate_simple_invoice.py');
        $command = sprintf(
            '%s %s %s < %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($storedFull),
            escapeshellarg($tmpPayload)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        @unlink($tmpPayload);

        if ($exitCode !== 0 || !file_exists($storedFull)) {
            Log::error('Simple invoice generation failed', [
                'output' => implode("\n", $output),
                'exit_code' => $exitCode,
            ]);
            return redirect()
                ->route('documents.index')
                ->withErrors('Failed to generate invoice. Please try again.');
        }

        $document = Document::create([
            'user_id' => Auth::id(),
            'original_name' => 'Invoice ' . ($validated['invoice_number'] ?? $uuid) . '.pdf',
            'path' => $storedRelative,
            'original_backup_path' => $this->createOriginalBackup($storedRelative),
            'mime_type' => 'application/pdf',
            'size_bytes' => filesize($storedFull),
            'mode' => 'guided',
            'template_type' => 'invoice',
            'template_slug' => $validated['style'] ?? 'default',
            'form_data' => $payload, // Save initial payload as form data
        ]);

        // Auto-download fonts
        $fontScript = base_path('python/pdf-editor/auto_download_fonts.py');
        $fontCommand = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($pythonBinary),
            escapeshellarg($fontScript),
            escapeshellarg($storedFull)
        );
        exec($fontCommand);

        $editUrl = route('documents.guided', $document);
        if ($validated['style'] ?? null) {
            $editUrl .= '?style=' . urlencode($validated['style']);
        }

        return redirect($editUrl)
            ->with('status', 'Invoice created. Customize it below.');
    }

    /**
     * Create a document from a guided template (newsletter, NDA, purchase order, etc.).
     */
    public function createFromGuidedTemplate(Request $request)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            '_template_type' => ['required', 'string', 'in:newsletter,business,realestate'],
            '_template_slug' => ['required', 'string', 'max:100'],
        ]);

        $templateType = $validated['_template_type'];
        $templateSlug = $validated['_template_slug'];

        // Enforce one template per type: redirect to existing if found
        $existing = Document::where('mode', 'guided')
            ->where('template_type', $templateType)
            ->where('template_slug', $templateSlug)
            ->first();

        if ($existing) {
            return redirect()
                ->route('documents.guided', ['document' => $existing, 'template_type' => $templateType, 'template_slug' => $templateSlug])
                ->with('status', 'You already have this template. Editing existing one.');
        }

        // Look up the template to get defaults
        $template = GuidedTemplate::where('slug', $templateSlug)->where('is_active', true)->first();
        if (!$template) {
            return redirect()->route('documents.index')->withErrors('Template not found.');
        }

        $uuid = Str::uuid()->toString();
        $storedRelative = 'documents/' . $uuid . '.pdf';
        $storedFull = Storage::path($storedRelative);

        Storage::makeDirectory('documents');

        // Build payload from template defaults
        $payload = array_merge($template->defaults ?? [], [
            'template_type' => $templateType,
            'template_slug' => $templateSlug,
        ]);

        $tmpPayload = tempnam(sys_get_temp_dir(), 'tpl_');
        file_put_contents($tmpPayload, json_encode($payload, JSON_UNESCAPED_UNICODE));

        $script = base_path('python/pdf-editor/generate_template.py');
        $command = sprintf(
            '%s %s %s < %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($storedFull),
            escapeshellarg($tmpPayload)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        @unlink($tmpPayload);

        if ($exitCode !== 0 || !file_exists($storedFull)) {
            Log::error('Guided template generation failed', [
                'template_type' => $templateType,
                'template_slug' => $templateSlug,
                'output' => implode("\n", $output),
                'exit_code' => $exitCode,
            ]);
            return redirect()
                ->route('documents.index')
                ->withErrors('Failed to generate template. Please try again.');
        }

        $document = Document::create([
            'user_id' => Auth::id(),
            'original_name' => $template->name . '.pdf',
            'path' => $storedRelative,
            'original_backup_path' => $this->createOriginalBackup($storedRelative),
            'mime_type' => 'application/pdf',
            'size_bytes' => filesize($storedFull),
            'mode' => 'guided',
            'template_type' => $templateType,
            'template_slug' => $templateSlug,
            'form_data' => $payload,
        ]);

        // Auto-download fonts
        $fontScript = base_path('python/pdf-editor/auto_download_fonts.py');
        $fontCommand = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($pythonBinary),
            escapeshellarg($fontScript),
            escapeshellarg($storedFull)
        );
        exec($fontCommand);

        return redirect()
            ->route('documents.guided', ['document' => $document, 'template_type' => $templateType, 'template_slug' => $templateSlug])
            ->with('status', $template->name . ' created. Customize it below.');
    }

    /**
     * Regenerate the invoice PDF in-place from the guided form.
     */
    public function regenerateInvoice(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'company_name'     => ['nullable', 'string', 'max:200'],
            'company_address'  => ['nullable', 'string', 'max:500'],
            'customer_name'    => ['nullable', 'string', 'max:200'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'invoice_number'   => ['nullable', 'string', 'max:50'],
            'invoice_date'     => ['nullable', 'string', 'max:30'],
            'due_date'         => ['nullable', 'string', 'max:30'],
            'items'            => ['nullable', 'array'],
            'items.*.qty'         => ['nullable', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string', 'max:200'],
            'items.*.unit_price'  => ['nullable', 'numeric', 'min:0'],
            'discount_label'   => ['nullable', 'string', 'max:100'],
            'discount_amount'  => ['nullable', 'numeric', 'min:0'],
            'terms'            => ['nullable', 'string', 'max:2000'],
            'style'            => ['nullable', 'string', 'in:default,bold_red'],
            'paid_in_full'     => ['nullable', 'boolean'],
        ]);

        $storedFull = Storage::path($document->path);

        $payload = json_encode($validated, JSON_UNESCAPED_UNICODE);

        // Write payload to temp file to avoid shell escaping issues with newlines
        $tmpPayload = tempnam(sys_get_temp_dir(), 'inv_');
        file_put_contents($tmpPayload, $payload);

        $script = base_path('python/pdf-editor/generate_simple_invoice.py');
        $command = sprintf(
            '%s %s %s < %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($storedFull),
            escapeshellarg($tmpPayload)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        @unlink($tmpPayload);

        if ($exitCode !== 0) {
            Log::error('Invoice regeneration failed', [
                'output' => implode("\n", $output),
                'exit_code' => $exitCode,
            ]);
            return response()->json(['error' => 'Failed to generate invoice.'], 500);
        }

        $document->update([
            'size_bytes' => filesize($storedFull),
        ]);

        // Auto-download fonts
        $fontScript = base_path('python/pdf-editor/auto_download_fonts.py');
        $fontCommand = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($pythonBinary),
            escapeshellarg($fontScript),
            escapeshellarg($storedFull)
        );
        exec($fontCommand);

        return response()->json([
            'success' => true,
            'python_binary' => $pythonBinary,
        ]);
    }

    /**
     * Regenerate a guided template PDF in-place (newsletter, NDA, purchase order).
     */
    public function regenerateTemplate(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $data = $request->all();
        $templateType = $data['template_type'] ?? '';
        $templateSlug = $data['template_slug'] ?? '';

        if (!in_array($templateType, ['newsletter', 'business', 'realestate'])) {
            return response()->json(['error' => 'Invalid template type.'], 422);
        }

        $storedFull = Storage::path($document->path);

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);

        $tmpPayload = tempnam(sys_get_temp_dir(), 'tpl_');
        file_put_contents($tmpPayload, $payload);

        $script = base_path('python/pdf-editor/generate_template.py');
        $command = sprintf(
            '%s %s %s < %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($storedFull),
            escapeshellarg($tmpPayload)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        @unlink($tmpPayload);

        if ($exitCode !== 0) {
            Log::error('Template regeneration failed', [
                'template_type' => $templateType,
                'template_slug' => $templateSlug,
                'output' => implode("\n", $output),
                'exit_code' => $exitCode,
            ]);
            return response()->json(['error' => 'Failed to regenerate template.'], 500);
        }

        $document->update([
            'size_bytes' => filesize($storedFull),
        ]);

        // Auto-download fonts
        $fontScript = base_path('python/pdf-editor/auto_download_fonts.py');
        $fontCommand = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($pythonBinary),
            escapeshellarg($fontScript),
            escapeshellarg($storedFull)
        );
        exec($fontCommand);

        return response()->json(['success' => true]);
    }

    /**
     * Save guided form data without generating PDF.
     */
    public function saveGuidedFormData(Request $request, Document $document)
    {
        $formData = $request->input('form_data', null);

        if (!$formData || !is_array($formData)) {
            return response()->json(['error' => 'No form data provided.'], 422);
        }

        $document->update(['form_data' => $formData]);

        return response()->json(['success' => true, 'message' => 'Form data saved.']);
    }

    /**
     * Convert HTML content to PDF using fitz.Story (PyMuPDF).
     */
    public function convertHtmlToPdf(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $html = $request->input('html', '');
        $css  = $request->input('css', '');
        $formData = $request->input('form_data', null); // New: Allow saving form state

        if (empty(trim($html))) {
            return response()->json(['error' => 'No HTML content provided.'], 422);
        }

        // If form data provided, save it to the document
        if ($formData) {
            $document->update(['form_data' => $formData]);
        }

        $storedFull = Storage::path($document->path);

        $payload = json_encode(['html' => $html, 'css' => $css], JSON_UNESCAPED_UNICODE);
        $tmpPayload = tempnam(sys_get_temp_dir(), 'html_');
        file_put_contents($tmpPayload, $payload);

        $script = base_path('python/pdf-editor/html_to_pdf.py');
        $command = sprintf(
            '%s %s %s < %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($storedFull),
            escapeshellarg($tmpPayload)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        @unlink($tmpPayload);

        if ($exitCode !== 0) {
            Log::error('HTML-to-PDF conversion failed', [
                'output' => implode("\n", $output),
                'exit_code' => $exitCode,
            ]);
            return response()->json(['error' => 'Failed to convert HTML to PDF.'], 500);
        }

        $document->update([
            'size_bytes' => filesize($storedFull),
        ]);

        return response()->json(['success' => true]);
    }

    public function processOcr(Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        // Run OCR extraction in background
        $fullPath = Storage::path($document->path);
        $pythonScript = base_path('python/pdf-editor/extract_pdf_text.py');
        $documentId = $document->id;
        
        // Execute Python script in background (non-blocking)
        $command = sprintf(
            '%s %s %s %d > /dev/null 2>&1 &',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($fullPath),
            $documentId
        );
        
        exec($command);
        
        return response()->json([
            'success' => true,
            'message' => 'OCR processing started in background'
        ]);
    }

    public function processFitz(Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        // Run PyMuPDF extraction in background
        $fullPath = Storage::path($document->path);
        $pythonScript = base_path('python/pdf-editor/extract_pdf_pymupdf.py');
        $documentId = $document->id;
        $userEmail = $this->resolveEditorEmail();
        $sessionId = session()->getId();
        
        // Execute Python script in background (non-blocking)
        $command = sprintf(
            '%s %s %s %d %s %s > /dev/null 2>&1 &',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($fullPath),
            $documentId,
            escapeshellarg($userEmail),
            escapeshellarg($sessionId)
        );
        
        exec($command);
        
        return response()->json([
            'success' => true,
            'message' => 'PyMuPDF extraction started in background'
        ]);
    }

    public function getFitzExtractionData(Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $userEmail = $this->resolveEditorEmail();
        $sessionId = session()->getId();
        $fullPath = Storage::path($document->path);
        $extraction = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);

        if (!file_exists($fullPath)) {
            return response()->json([
                'success' => false,
                'message' => 'PDF file not found.'
            ], 404);
        }

        $needsRefresh = false;
        if (!$extraction) {
            $needsRefresh = true;
        } else {
            $pdfModifiedTime = filemtime($fullPath) ?: 0;
            $extractionTime = strtotime((string) $extraction->created_at) ?: 0;
            $scriptPath = base_path('python/pdf-editor/extract_pdf_pymupdf.py');
            $scriptModifiedTime = file_exists($scriptPath) ? (filemtime($scriptPath) ?: 0) : 0;

            if ($pdfModifiedTime > $extractionTime || $scriptModifiedTime > $extractionTime) {
                $needsRefresh = true;
            } else {
                $extractionData = json_decode($extraction->extraction_data, true);
                $hasFontXref = false;
                if (is_array($extractionData)) {
                    foreach ($extractionData as $page) {
                        if (!empty($page['words'])) {
                            $firstWord = $page['words'][0];
                            if (array_key_exists('font_xref', $firstWord)) {
                                $hasFontXref = true;
                            }
                            break;
                        }
                    }
                }

                if (!$hasFontXref) {
                    $needsRefresh = true;
                }
            }
        }

        if ($needsRefresh) {
            [$returnCode, $output] = $this->runFitzExtraction($document, $fullPath, $userEmail, $sessionId, $pythonBinary);
            if ($returnCode === 0) {
                $extraction = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);
            } else {
                \Log::error('Failed to refresh FITZ extraction data', [
                    'document_id' => $document->id,
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output),
                ]);
            }
        }

        if (!$extraction || !isset($extraction->extraction_data)) {
            return response()->json([
                'success' => false,
                'message' => 'No extraction data found. Processing may still be in progress.'
            ], 200); // Return 200 so clients parse JSON easily
        }

        // Load embedded fonts metadata if available
        $embeddedFonts = null;
        $embeddedFontsPath = storage_path("app/temp/embedded_fonts_{$document->id}.json");
        if (file_exists($embeddedFontsPath)) {
            $embeddedFonts = json_decode(file_get_contents($embeddedFontsPath), true);
        } else {
            // Try to extract fonts on-the-fly if not yet done
            if (file_exists($fullPath)) {
                $docId = $document->id;
                $escapedPath = escapeshellarg($fullPath);
                $escapedJsonPath = escapeshellarg($embeddedFontsPath);
                $command = sprintf(
                    '%s -c "import sys, json; sys.path.insert(0, \'%s\'); from extract_pdf_pymupdf import extract_embedded_fonts; fonts = extract_embedded_fonts(%s, %d); f = open(%s, \'w\'); json.dump(fonts, f); f.close()" 2>&1',
                    escapeshellarg($pythonBinary),
                    base_path('python/pdf-editor'),
                    $escapedPath,
                    $docId,
                    $escapedJsonPath
                );
                exec($command);
                if (file_exists($embeddedFontsPath)) {
                    $embeddedFonts = json_decode(file_get_contents($embeddedFontsPath), true);
                }
            }
        }

        return response()->json([
            'success' => true,
            'extraction_data' => json_decode($extraction->extraction_data, true),
            'total_pages' => $extraction->total_pages,
            'total_words' => $extraction->total_words,
            'full_text' => $extraction->full_text,
            'embedded_fonts' => $embeddedFonts,
        ]);
    }

    public function edit(Document $document)
    {
        return view('documents.edit', [
            'document' => $document,
            'activeTab' => 'pdf-editor',
            'pdfSaveMode' => strtolower((string) config('pdf_editor.save_mode', 'full_page_save')),
            'savedEditPreviewUrl' => route('documents.savedEdit', $document),
        ]);
    }

    public function savedEditPreview(Request $request, Document $document)
    {
        $selectedEntry = trim((string) $request->query('entry', 'latest'));
        $preview = $this->readLiveSavePreview($document, $selectedEntry);
        if (!$preview && $selectedEntry !== 'latest') {
            $preview = $this->readLiveSavePreview($document);
            $selectedEntry = 'latest';
        }

        return view('documents.saved', [
            'document' => $document,
            'preview' => $preview,
            'previewHistory' => $this->listLiveSavePreviewHistory($document),
            'selectedEntry' => $selectedEntry,
        ]);
    }

    public function savedEditPreviewImage(Request $request, Document $document, string $variant)
    {
        abort_unless(in_array($variant, ['before', 'redacted', 'final'], true), 404);

        $entry = trim((string) $request->query('entry', 'latest'));
        $safeEntry = preg_replace('/[^A-Za-z0-9T_\-:.Z]/', '', $entry) ?: 'latest';
        $path = $this->liveSavePreviewDirectory($document) . '/' . $safeEntry . '-' . $variant . '.png';
        if (!is_file($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function ai($id)
    {
        // Check for AiDocument first (User requested URL behavior)
        $aiDocument = \App\Models\AiDocument::with('document')->find($id);
        if ($aiDocument) {
             return view('documents.edit', [
                'document' => $aiDocument->document,
                'aiDocument' => $aiDocument,
                'activeTab' => 'extracted-text',
            ]);
        }

        // Fallback to standard Document
        $document = Document::find($id);
        if ($document) {
            return view('documents.edit', [
                'document' => $document,
                'activeTab' => 'extracted-text',
            ]);
        }

        abort(404);
    }

    public function createAi(Request $request)
    {
        $validated = $request->validate([
            'document_id' => 'required|exists:documents,id',
        ]);

        $aiDocument = \App\Models\AiDocument::create([
            'document_id' => $validated['document_id'],
            'session_id' => Str::uuid(),
            'email' => $this->resolveEditorEmail(null),
        ]);

        return redirect()->to(route('documents.ai', ['document' => $aiDocument->id]));
    }


    public function guided(Document $document)
    {
        // Use saved template metadata if available, otherwise fallback to query params or default
        $templateType = $document->template_type ?? request('template_type', 'invoice');
        $templateSlug = $document->template_slug ?? request('template_slug', 'default');
        
        // Pass saved form data if available
        $formData = $document->form_data ?? [];

        // If no saved data, maybe we can load defaults from the template definition
        $templateDefaults = [];
        if (empty($formData) && $templateSlug) {
            $template = GuidedTemplate::where('slug', $templateSlug)->first();
            if ($template) {
                $templateDefaults = $template->defaults ?? [];
            }
        }

        return view('documents.edit', [
            'document' => $document,
            'activeTab' => 'guided-' . $templateType, // Dynamically activate the correct tab
            'templateType' => $templateType,
            'templateSlug' => $templateSlug,
            'templateDefaults' => $templateDefaults, // Only used if no form_data
            'formData' => $formData,                 // New: saved input values
        ]);
    }

    public function fullscreen(Document $document)
    {
        return view('documents.fullscreen', [
            'document' => $document,
        ]);
    }

    public function editExtractedText(Document $document)
    {
        $userEmail = $this->resolveEditorEmail();
        $sessionId = session()->getId();
        $extraction = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);

        if (!$extraction) {
            return redirect()->back()->with('error', 'No extraction data found. Please wait for processing to complete.');
        }

        $extractionData = json_decode($extraction->extraction_data, true);

        return view('documents.edit-extracted', [
            'document' => $document,
            'extraction' => $extraction,
            'extractionData' => $extractionData,
        ]);
    }

    public function file(Document $document)
    {
        $fullPath = Storage::path($document->path);
        
        // Get file modification time for ETag
        $lastModified = filemtime($fullPath);
        $etag = md5($lastModified . filesize($fullPath));

        return response()->file($fullPath, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="' . $document->original_name . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
        ]);
    }

    public function annotationAsset(Document $document, string $filename)
    {
        abort_unless($filename !== '' && basename($filename) === $filename, 404);

        $relativePath = sprintf('annotation-assets/documents/%d/%s', $document->id, $filename);
        $absolutePath = $this->annotationAssets()->assetAbsolutePath($relativePath);

        if ($absolutePath === null) {
            abort(404);
        }

        $mimeType = Storage::disk('public')->mimeType($relativePath) ?: 'application/octet-stream';
        $lastModified = @filemtime($absolutePath) ?: time();
        $etag = md5($relativePath . '|' . $lastModified . '|' . (@filesize($absolutePath) ?: 0));

        return response()->file($absolutePath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
        ]);
    }

    public function originalFile(Document $document)
    {
        if (!$document->original_backup_path || !Storage::exists($document->original_backup_path)) {
            abort(404);
        }

        $fullPath = Storage::path($document->original_backup_path);
        if (!file_exists($fullPath)) {
            abort(404);
        }

        $lastModified = filemtime($fullPath);
        $etag = md5($lastModified . filesize($fullPath));

        return response()->file($fullPath, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="' . $document->original_name . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
        ]);
    }

    public function flattenRotations(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $file = $validated['pdf'];
        $tempPath = $file->getPathname();
        $outputPath = tempnam(sys_get_temp_dir(), 'flattened_') . '.pdf';
        
        $pythonScript = base_path('python/pdf-editor/flatten_pdf_rotations.py');
        
        $command = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($tempPath),
            escapeshellarg($outputPath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            Log::error('Rotation flattening failed', [
                'document_id' => $document->id,
                'output' => implode("\n", $output)
            ]);
            
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }
            
            return response()->json(['error' => 'Failed to flatten rotations'], 500);
        }
        
        // Return the flattened PDF
        return response()->file($outputPath, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    public function applyRotations(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'rotations' => ['required', 'string'], // JSON string
        ]);

        $file = $validated['pdf'];
        $tempPath = $file->getPathname();
        $rotations = json_decode($validated['rotations'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($rotations)) {
            return response()->json(['error' => 'Invalid rotation data'], 400);
        }
        
        $pythonScript = base_path('python/pdf-editor/rotate_pdf_page.py');
        
        // Apply rotations one by one
        foreach ($rotations as $pageIndex => $rotation) {
            if ($rotation == 0) continue;
            
            $pageNumber = (int)$pageIndex + 1;
            $tempOutputPath = tempnam(sys_get_temp_dir(), 'rotated_') . '.pdf';
            
            $command = sprintf(
                '%s %s %s %s %d %d 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($pythonScript),
                escapeshellarg($tempPath),
                escapeshellarg($tempOutputPath),
                $pageNumber,
                (int)$rotation
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                Log::error('Rotation failed', [
                    'document_id' => $document->id,
                    'page_number' => $pageNumber,
                    'output' => implode("\n", $output)
                ]);
                
                if (file_exists($tempOutputPath)) {
                    unlink($tempOutputPath);
                }
                
                return response()->json(['error' => 'Rotation failed'], 500);
            }
            
            // Replace input with output for next rotation
            if (file_exists($tempOutputPath)) {
                copy($tempOutputPath, $tempPath);
                unlink($tempOutputPath);
            }
        }
        
        // Return the rotated PDF
        return response()->file($tempPath, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Normalize a PDF file using qpdf to fix structural issues.
     * pdf-lib (used by the frontend for shapes/annotations) creates PDF structures
     * (ExtGState dicts, Contents arrays) that PyMuPDF cannot parse.
     * qpdf rewrites the PDF structure without touching font encodings or glyphs,
     * unlike Ghostscript which re-encodes fonts and corrupts space characters.
     *
     * @param string $pdfPath Absolute path to the PDF file (modified in-place)
     * @return bool Whether normalization succeeded
     */
    private function normalizePdfWithQpdf(string $pdfPath): bool
    {
        // qpdf --replace-input rewrites the PDF structure in-place
        // It fixes cross-reference tables, object numbering, and stream issues
        // without modifying content streams, fonts, or glyph encodings
        $command = sprintf(
            'qpdf --replace-input --normalize-content=n %s 2>&1',
            escapeshellarg($pdfPath)
        );
        
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            \Log::info('PDF normalized with qpdf', ['path' => $pdfPath]);
            return true;
        }
        
        // qpdf exit code 3 = warnings but file may still be damaged.
        if ($returnCode === 3) {
            $warnings = implode("\n", $output);
            $fatalWarningPatterns = [
                'too many errors; giving up on reading object',
                'operation for dictionary attempted on object of type null',
                'unknown token while reading object',
            ];
            foreach ($fatalWarningPatterns as $pattern) {
                if (stripos($warnings, $pattern) !== false) {
                    \Log::warning('qpdf normalization reported fatal warning pattern', [
                        'path' => $pdfPath,
                        'pattern' => $pattern,
                        'warnings' => implode("\n", array_slice($output, -20)),
                    ]);
                    return false;
                }
            }
            \Log::info('PDF normalized with qpdf (with warnings)', [
                'path' => $pdfPath,
                'warnings' => implode("\n", array_slice($output, -5))
            ]);
            return true;
        }
        
        \Log::warning('qpdf normalization failed', [
            'path' => $pdfPath,
            'return_code' => $returnCode,
            'output' => implode("\n", array_slice($output, -10))
        ]);
        return false;
    }

    public function save(Request $request, Document $document)
    {
        $validated = $request->validate([
            'edited_pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'session_id' => ['nullable', 'string'],
            'acro_form_entries' => ['nullable'],
        ]);

        $sessionId = is_string($validated['session_id'] ?? null)
            ? trim((string) $validated['session_id'])
            : '';
        $rawAcroFormEntries = $request->input('acro_form_entries', null);
        $acroFormEntriesProvided = $request->exists('acro_form_entries');
        if (is_string($rawAcroFormEntries) && trim($rawAcroFormEntries) !== '') {
            $decodedAcroFormEntries = json_decode($rawAcroFormEntries, true);
            if (!is_array($decodedAcroFormEntries)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'AcroForm payload is invalid.',
                ], 422);
            }
            $rawAcroFormEntries = $decodedAcroFormEntries;
        }
        $acroFormEntries = $this->normalizeAcroFormEntriesForPersistence(
            is_array($rawAcroFormEntries) ? $rawAcroFormEntries : []
        );

        if ($response = $this->consumeMonthlyActionQuota($request)) {
            return $response;
        }

        $file = $validated['edited_pdf'];
        $tempPath = $file->getPathname();
        $incomingBytes = @file_get_contents($tempPath);
        $incomingSize = $incomingBytes === false ? 0 : strlen($incomingBytes);

        // Hard guard: reject obviously malformed/truncated uploads from frontend.
        if ($incomingSize < 2048) {
            \Log::warning('Rejected suspiciously small edited PDF upload', [
                'document_id' => $document->id,
                'incoming_size' => $incomingSize,
            ]);
            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => 'Save PDF',
                    'category' => 'pdf_save',
                    'details' => ['incoming_size' => $incomingSize, 'error' => 'Edited PDF payload is invalid or truncated.'],
                    'document_id' => $document->id,
                    'status' => 'failed',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }
            return response()->json([
                'ok' => false,
                'message' => 'Edited PDF payload is invalid or truncated.',
            ], 422);
        }

        // Pre-save rollback point for failed normalization.
        $fullPath = Storage::path($document->path);
        $preSaveBackupPath = $fullPath . '.presave.bak';
        if (file_exists($fullPath)) {
            @copy($fullPath, $preSaveBackupPath);
        }
        
        Storage::put($document->path, $incomingBytes);

        // CRITICAL: Normalize the PDF with qpdf to fix pdf-lib structural issues.
        // pdf-lib creates ExtGState dicts and Content stream arrays that PyMuPDF cannot parse,
        // causing "syntax error: invalid key in dict" and "not a dict (null)" errors.
        // qpdf rewrites the structure without touching fonts (unlike Ghostscript which
        // re-encodes fonts to Type0/CID and corrupts space character glyphs).
        if (!$this->normalizePdfWithQpdf($fullPath)) {
            // qpdf could not normalize this PDF — keep the pdf-lib version as-is.
            // pdf-lib output is valid PDF; qpdf just cannot parse some exotic
            // structures.  Rolling back would discard the user's edits, so we
            // proceed with the un-normalized file and log a warning.
            \Log::warning('qpdf normalization failed for save — keeping pdf-lib output as-is', [
                'document_id' => $document->id,
                'path' => $fullPath,
            ]);
        }

        if (file_exists($preSaveBackupPath)) {
            @unlink($preSaveBackupPath);
        }

        // Re-read file size after normalization (size may have changed)
        $normalizedSize = file_exists($fullPath) ? filesize($fullPath) : $file->getSize();

        // CRITICAL: Use direct DB update to avoid updating 'updated_at' timestamp
        // This prevents prepareOverlay() from auto-refreshing extraction data
        // Shapes/signatures/annotations are visual only and should NOT trigger extraction refresh
        $document->mime_type = $file->getClientMimeType();
        $document->size_bytes = $normalizedSize;
        $document->saveQuietly(); // Saves without updating timestamps or firing events

        // IMPORTANT: save() should NEVER update pdf_extractions_fitz data
        // Extraction data should ONLY be updated by the overlay editor via saveEdits()
        // Shapes, signatures, and text annotations are visual stamps that don't affect extraction
        if (Auth::check()) {
            UserActivity::create([
                'user_id' => Auth::id(),
                'action' => 'Save PDF',
                'category' => 'pdf_save',
                'details' => ['size_bytes' => $normalizedSize],
                'document_id' => $document->id,
                'status' => 'success',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        if ($sessionId !== '' && $acroFormEntriesProvided) {
            $this->upsertPdfAcroFormSessionState(
                $document,
                $sessionId,
                $acroFormEntries,
                Auth::id(),
                'saved'
            );
        }

        return response()->json([
            'ok' => true,
            'message' => 'Document saved.',
        ]);
    }

    public function rename(Request $request, Document $document)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:240'],
        ]);

        $normalizedName = $this->normalizeDocumentOriginalName($document, (string) $validated['name']);
        if ($normalizedName === '') {
            return response()->json([
                'success' => false,
                'message' => 'Document name cannot be empty.',
            ], 422);
        }

        if ($document->original_name !== $normalizedName) {
            $timestamps = $document->timestamps;
            try {
                $document->timestamps = false;
                $document->original_name = $normalizedName;
                $document->saveQuietly();
            } finally {
                $document->timestamps = $timestamps;
            }
        }

        return response()->json([
            'success' => true,
            'original_name' => $document->original_name,
            'base_name' => pathinfo((string) $document->original_name, PATHINFO_FILENAME),
        ]);
    }

    public function destroy(Document $document)
    {
        // Delete related extraction data
        DB::table('pdf_extractions_fitz')
            ->where('document_id', $document->id)
            ->delete();
        
        Storage::delete($document->path);
        if ($document->original_backup_path) {
            Storage::delete($document->original_backup_path);
        }
        $document->delete();

        return redirect()
            ->route('documents.index')
            ->with('status', 'Document deleted.');
    }

    public function restoreOriginal(Request $request, Document $document)
    {
        if (!$document->original_backup_path) {
            return response()->json([
                'success' => false,
                'message' => 'No original backup exists for this document.',
            ], 422);
        }

        if (!Storage::exists($document->original_backup_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Original backup file was not found.',
            ], 404);
        }

        try {
            $originalBytes = Storage::get($document->original_backup_path);
            if ($originalBytes === false) {
                throw new \RuntimeException('Failed to read original backup bytes.');
            }

            Storage::put($document->path, $originalBytes);
            $deletedAnnotationCount = 0;
            $restoredAt = now();

            $cleanPath = Storage::path('temp/clean_' . $document->id . '.pdf');
            if (file_exists($cleanPath)) {
                @unlink($cleanPath);
            }

            $embeddedFontsPath = storage_path("app/temp/embedded_fonts_{$document->id}.json");
            if (file_exists($embeddedFontsPath)) {
                @unlink($embeddedFontsPath);
            }

            $fullPath = Storage::path($document->path);
            $sizeBytes = file_exists($fullPath) ? filesize($fullPath) : null;

            $document->mime_type = 'application/pdf';
            if ($sizeBytes !== null) {
                $document->size_bytes = $sizeBytes;
            }
            $document->updated_at = $restoredAt;
            $document->saveQuietly();

            DB::table('pdf_extractions_fitz')
                ->where('document_id', $document->id)
                ->delete();

            PdfState::query()
                ->where('document_id', $document->id)
                ->where('state', 'extracted')
                ->delete();

            PdfAcroForm::query()
                ->where('document_id', $document->id)
                ->delete();

            $editorEmail = $this->resolveEditorEmail('');
            $annotationCleanupQuery = PdfState::query()
                ->where('document_id', $document->id);

            if ($editorEmail !== '') {
                $annotationCleanupQuery->where('user_email', $editorEmail);
            } else {
                $annotationCleanupQuery->where('session_id', $request->session()->getId());
            }

            $deletedAnnotationCount = $annotationCleanupQuery->delete();

            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => 'Restore Original PDF',
                    'category' => 'pdf_save',
                    'details' => [
                        'deleted_annotation_count' => $deletedAnnotationCount,
                        'restored_at' => $restoredAt->toIso8601String(),
                    ],
                    'document_id' => $document->id,
                    'status' => 'success',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Original PDF restored.',
                'deleted_annotations' => $deletedAnnotationCount,
                'restored_at' => $restoredAt->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to restore original PDF backup', [
                'document_id' => $document->id,
                'backup_path' => $document->original_backup_path,
                'path' => $document->path,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to restore original PDF.',
            ], 500);
        }
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:documents,id',
        ]);

        $documents = Document::whereIn('id', $validated['ids'])->get();
        $count = 0;

        foreach ($documents as $document) {
            // Delete related extraction data
            DB::table('pdf_extractions_fitz')
                ->where('document_id', $document->id)
                ->delete();
            
            Storage::delete($document->path);
            if ($document->original_backup_path) {
                Storage::delete($document->original_backup_path);
            }
            $document->delete();
            $count++;
        }

        return redirect()
            ->route('documents.index')
            ->with('status', "$count documents deleted.");
    }

    public function prepareOverlay(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        // Check if the PDF file exists on disk
        $fullPath = Storage::path($document->path);
        if (!file_exists($fullPath)) {
            \Log::error('PDF file not found for overlay', ['document_id' => $document->id, 'path' => $document->path, 'fullPath' => $fullPath]);
            return response()->json(['success' => false, 'error' => 'PDF file not found. The file may have been deleted or moved.'], 404);
        }

        $userEmail = $this->resolveEditorEmail();
        $sessionId = session()->getId();
        $forceRefresh = $request->boolean('force_refresh', false);

        // Optional hard refresh path used by overlay toggle to avoid stale extraction state.
        if ($forceRefresh) {
            [$returnCode, $output] = $this->runFitzExtraction($document, $fullPath, $userEmail, $sessionId, $pythonBinary);
            if ($returnCode !== 0) {
                \Log::error('Forced PDF extraction failed', [
                    'document_id' => $document->id,
                    'python_binary' => $pythonBinary,
                    'returnCode' => $returnCode,
                    'output' => implode("\n", $output),
                ]);
                return response()->json(['success' => false, 'error' => 'Failed to refresh PDF extraction data.'], 500);
            }
        }
        
        $extraction = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);

        if (!$extraction) {
            // Run extraction automatically
            [$returnCode, $output] = $this->runFitzExtraction($document, $fullPath, $userEmail, $sessionId, $pythonBinary);
            
            if ($returnCode !== 0) {
                \Log::error('PDF extraction failed', ['document_id' => $document->id, 'python_binary' => $pythonBinary, 'returnCode' => $returnCode, 'output' => implode("\n", $output)]);
                return response()->json(['success' => false, 'error' => 'Failed to extract PDF text. Please try again.'], 500);
            }
            
            // Reload extraction data
            $extraction = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);

            if (!$extraction) {
                return response()->json(['success' => false, 'error' => 'Failed to extract PDF text data.'], 500);
            }
        } else {
            // Check if PDF has been modified since last extraction
            // If the file timestamp is newer, we need to re-extract to pick up burned-in annotations
            $fullPath = Storage::path($document->path);
            $pdfModifiedTime = file_exists($fullPath) ? filemtime($fullPath) : 0;
            $extractionTime = strtotime($extraction->created_at);
            
            // If PDF is newer than extraction, re-extract
            $scriptPath = base_path('python/pdf-editor/extract_pdf_pymupdf.py');
            $scriptModifiedTime = file_exists($scriptPath) ? filemtime($scriptPath) : 0;

            if ($pdfModifiedTime > $extractionTime) {
                \Log::info('PDF modified since extraction, re-extracting', [
                    'pdf_time' => date('Y-m-d H:i:s', $pdfModifiedTime),
                    'extraction_time' => $extraction->created_at
                ]);

                [$returnCode, $output] = $this->runFitzExtraction($document, $fullPath, $userEmail, $sessionId, $pythonBinary);
                if ($returnCode === 0) {
                    $extraction = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);
                } else {
                    \Log::error('Failed to re-extract PDF after modification', ['output' => implode("\n", $output)]);
                }
            } elseif ($scriptModifiedTime > $extractionTime) {
                // Extraction code is newer than the cached extraction — re-run so fixes take effect
                \Log::info('Extraction script updated since last extraction, re-extracting', [
                    'script_time' => date('Y-m-d H:i:s', $scriptModifiedTime),
                    'extraction_time' => $extraction->created_at,
                    'document_id' => $document->id,
                ]);

                [$returnCode, $output] = $this->runFitzExtraction($document, $fullPath, $userEmail, $sessionId, $pythonBinary);
                if ($returnCode === 0) {
                    $extraction = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);
                } else {
                    \Log::error('Failed to re-extract after script update', [
                        'document_id' => $document->id,
                        'output' => implode("\n", $output),
                    ]);
                }
            }

            if (!$extraction) {
                return response()->json(['success' => false, 'error' => 'No extraction data available for this PDF.'], 500);
            }

            // Ensure extraction includes font_xref data (refresh if missing)
            $extractionData = json_decode($extraction->extraction_data, true);
            $hasFontXref = false;
            if (is_array($extractionData)) {
                foreach ($extractionData as $page) {
                    if (!empty($page['words'])) {
                        $firstWord = $page['words'][0];
                        if (array_key_exists('font_xref', $firstWord)) {
                            $hasFontXref = true;
                        }
                        break;
                    }
                }
            }
            if (!$hasFontXref) {
                [$returnCode, $output] = $this->runFitzExtraction($document, $fullPath, $userEmail, $sessionId, $pythonBinary);
                if ($returnCode === 0) {
                    $extraction = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);
                } else {
                    \Log::error('Failed to refresh extraction for missing font_xref', [
                        'document_id' => $document->id,
                        'output' => implode("\n", $output),
                    ]);
                }
            }
        }

        // Guard against race/failure cases where refresh succeeded but no row was stored.
        if (!$extraction || !isset($extraction->extraction_data)) {
            \Log::error('Overlay preparation aborted: extraction row missing after refresh', [
                'document_id' => $document->id,
            ]);
            return response()->json([
                'success' => false,
                'error' => 'No extraction data available for this PDF.',
            ], 500);
        }

        $this->materializeFitzExtractionToPdfState($document, $extraction);
        $this->ensurePdfAcroFormMaterialized($document, $fullPath, $pythonBinary);

        // Create clean PDF (with all text removed) for overlay editing
        $fullPath = Storage::path($document->path);
        $cleanPath = Storage::path('temp/clean_' . $document->id . '.pdf');
        $extractionFile = tempnam(sys_get_temp_dir(), 'tb_ext_' . $document->id . '_');
        if ($extractionFile === false) {
            $extractionFile = Storage::path('private/temp/extraction_' . $document->id . '_' . uniqid() . '.json');
        }
        
        // Always delete old clean PDF to ensure we get fresh version
        if (file_exists($cleanPath)) {
            unlink($cleanPath);
        }
        
        // Ensure temp directory exists
        $tempDir = dirname($cleanPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        // Save extraction data to temp file
        $writeOk = @file_put_contents($extractionFile, $extraction->extraction_data);
        if ($writeOk === false) {
            \Log::error('Failed to write extraction temp file for overlay prep', [
                'document_id' => $document->id,
                'extraction_file' => $extractionFile,
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to prepare temporary extraction file.',
            ], 500);
        }
        
        $pythonScript = base_path('python/pdf-editor/create_clean_pdf.py');
        $command = sprintf(
            '%s %s %s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($fullPath),
            escapeshellarg($extractionFile),
            escapeshellarg($cleanPath)
        );
        
        exec($command, $output, $returnCode);
        
        // Clean up temp extraction file
        if (file_exists($extractionFile)) {
            unlink($extractionFile);
        }
        
        if ($returnCode !== 0) {
            \Log::error('Failed to create clean PDF', ['python_binary' => $pythonBinary, 'output' => implode("\n", $output)]);
            return response()->json(['success' => false, 'error' => 'Failed to prepare PDF for editing.'], 500);
        }

        return response()->json(['success' => true]);
    }

    private function anchorChangedForTargetedSave(array $edit, float $tolerance = 0.75): bool
    {
        $originalBbox = $edit['original_bbox'] ?? null;
        $newBbox = $edit['bbox'] ?? null;
        if (!is_array($originalBbox) || !is_array($newBbox) || count($originalBbox) < 4 || count($newBbox) < 4) {
            return false;
        }

        return abs((float) $originalBbox[0] - (float) $newBbox[0]) > $tolerance
            || abs((float) $originalBbox[1] - (float) $newBbox[1]) > $tolerance;
    }

    private function normalizePdfStyleRunsForTargetedSave($runs): array
    {
        if (!is_array($runs)) {
            return [];
        }

        return array_map(static function ($run) {
            if (!is_array($run)) {
                return [];
            }

            $normalizeColor = static function ($value): string {
                $color = strtolower(trim((string) ($value ?? '')));
                if ($color === '') {
                    return '';
                }

                return str_starts_with($color, '#') ? $color : ('#' . $color);
            };

            return [
                'text' => preg_replace('/\s+/', ' ', trim((string) ($run['text'] ?? ''))),
                'font' => trim((string) ($run['font'] ?? '')),
                'font_size' => round((float) ($run['font_size'] ?? 0), 2),
                'font_weight' => trim((string) ($run['font_weight'] ?? '')),
                'italic' => (bool) ($run['italic'] ?? false),
                'bold' => (bool) ($run['bold'] ?? false),
                'underline' => (bool) ($run['underline'] ?? false),
                'hex_color' => $normalizeColor($run['hex_color'] ?? $run['color'] ?? ''),
                'left' => round((float) ($run['left'] ?? 0), 2),
                'top' => round((float) ($run['top'] ?? 0), 2),
                'width' => round((float) ($run['width'] ?? 0), 2),
                'height' => round((float) ($run['height'] ?? 0), 2),
                'origin_x' => round((float) ($run['origin_x'] ?? 0), 2),
                'origin_y' => round((float) ($run['origin_y'] ?? 0), 2),
            ];
        }, $runs);
    }

    private function stylesChangedForTargetedSave(array $edit): bool
    {
        $wordStyles = $this->normalizePdfStyleRunsForTargetedSave($edit['word_styles'] ?? null);
        $lineMetrics = $this->normalizePdfStyleRunsForTargetedSave($edit['line_metrics'] ?? null);
        if (!empty($wordStyles) && !empty($lineMetrics)) {
            return $wordStyles !== $lineMetrics;
        }

        $fontWeight = strtolower(trim((string) ($edit['font_weight'] ?? '')));
        $fontStyle = strtolower(trim((string) ($edit['font_style'] ?? 'normal')));
        $color = strtolower(trim((string) ($edit['color'] ?? '#000000')));
        $backgroundColor = strtolower(trim((string) ($edit['background_color'] ?? '')));
        $richHtml = strtolower((string) ($edit['rich_html'] ?? ''));
        if ($color !== '' && !str_starts_with($color, '#')) {
            $color = '#' . $color;
        }
        if ($backgroundColor !== '' && !str_starts_with($backgroundColor, '#') && $backgroundColor !== 'transparent') {
            $backgroundColor = '#' . $backgroundColor;
        }

        return !in_array($fontWeight, ['', '400', 'normal'], true)
            || $fontStyle !== 'normal'
            || !in_array($color, ['', '#000000'], true)
            || !in_array($backgroundColor, ['', 'transparent'], true)
            || str_contains($richHtml, 'background-color:')
            || !empty($edit['underline']);
    }

    private function isMaterialEditForTargetedSave(array $edit): bool
    {
        $originalText = preg_replace('/\s+/', ' ', trim((string) ($edit['original_text'] ?? '')));
        $newText = preg_replace('/\s+/', ' ', trim((string) ($edit['new_text'] ?? '')));

        if ($originalText !== $newText) {
            return true;
        }

        if ($this->anchorChangedForTargetedSave($edit)) {
            return true;
        }

        return $this->stylesChangedForTargetedSave($edit);
    }

    public function saveEdits(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'edits' => ['present', 'array'],
            'edits.*.page_number' => ['required', 'integer'],
            'edits.*.original_text' => ['present', 'string'],  // Changed from 'required' to 'present' - allow empty strings
            'edits.*.new_text' => ['nullable', 'string'],
            'edits.*.bbox' => ['required', 'array'],
            'edits.*.original_bbox' => ['nullable', 'array'],
            'edits.*.origin_x' => ['nullable', 'numeric'],
            'edits.*.origin_y' => ['nullable', 'numeric'],
            'edits.*.font_xref' => ['nullable', 'integer'],
            'edits.*.font' => ['required', 'string'],
            'edits.*.font_size' => ['required', 'numeric'],
            'edits.*.font_weight' => ['nullable', 'string'],
            'edits.*.font_style' => ['nullable', 'string'],
            'edits.*.underline' => ['nullable', 'boolean'],
            'edits.*.line_height' => ['nullable', 'numeric'],
            'edits.*.color' => ['nullable', 'string'],
            'edits.*.background_color' => ['nullable', 'string'],
            'edits.*.rich_html' => ['nullable', 'string'],
            'edits.*.cleanup_group_bbox' => ['nullable', 'array'],
            'edits.*.line_metrics' => ['nullable', 'array'],
            'edits.*.line_metrics.*' => ['nullable', 'array'],
            'edits.*.synthetic_textbox' => ['nullable', 'boolean'],
            'edits.*.word_styles' => ['nullable', 'array'],
            'edits.*.word_styles.*' => ['nullable', 'array'],
            'edits.*.source_content_ops' => ['nullable', 'array'],
            'edits.*.source_content_ops.*.xref' => ['nullable', 'integer'],
            'edits.*.source_content_ops.*.start' => ['nullable', 'integer'],
            'edits.*.source_content_ops.*.end' => ['nullable', 'integer'],
            'edits.*.source_content_ops.*.operator' => ['nullable', 'string'],
            'edits.*.source_content_ops.*.operator_text' => ['nullable', 'string'],
            'edits.*.source_content_ops.*.matched_text' => ['nullable', 'string'],
            'edits.*.source_content_ops.*.collapsed_operator_text' => ['nullable', 'string'],
            'edits.*.source_content_ops.*.tm_x' => ['nullable', 'numeric'],
            'edits.*.source_content_ops.*.tm_y' => ['nullable', 'numeric'],
            'edits.*.preserve_partial_block' => ['nullable', 'boolean'],
            'edits.*.force_reinsert' => ['nullable', 'boolean'],
            'skip_refresh' => ['nullable', 'boolean'],
            'finalize_live_save' => ['nullable', 'boolean'],
            'working_copy_token' => ['nullable', 'string'],
        ]);

        if ($response = $this->consumeMonthlyActionQuota($request)) {
            return $response;
        }

        $edits = $validated['edits'] ?? [];
        $saveMode = strtolower((string) config('pdf_editor.save_mode', 'full_page_save'));
        $finalizeLiveSave = (bool) ($validated['finalize_live_save'] ?? false);
        $workingCopyToken = isset($validated['working_copy_token']) && is_string($validated['working_copy_token'])
            ? trim((string) $validated['working_copy_token'])
            : '';

        if (empty($edits)) {
            if ($saveMode === 'live_save' && $finalizeLiveSave) {
                if ($workingCopyToken !== '') {
                    $snapshotPath = $this->liveSaveSnapshotPath($document, $workingCopyToken);
                    if (!is_file($snapshotPath)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Working-copy snapshot not found.',
                        ], 404);
                    }

                    $fullPath = Storage::path($document->path);
                    if (!@copy($snapshotPath, $fullPath)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Failed to commit working-copy snapshot.',
                        ], 500);
                    }
                    @unlink($snapshotPath);
                }

                $this->refreshOverlayExtractionArtifacts($document, $pythonBinary, (bool) ($validated['skip_refresh'] ?? false));
                $document->touch();

                return response()->json(array_merge([
                    'success' => true,
                    'message' => 'Live-save finalized successfully',
                ], $this->buildLiveSavePreviewPayload($document)));
            }

            return response()->json([
                'success' => true,
                'message' => 'No overlay edits to save',
            ]);
        }

        // GUARD MECHANISM: Check if any entire page is being nulled out
        $pageTextCounts = [];
        $pageDimensions = [];
        
        // Get current extraction to know original text per page
        $extractionData = [];
        $extraction = DB::table('pdf_extractions_fitz')
            ->where('document_id', $document->id)
            ->orderBy('id', 'desc')
            ->first();
            
        if ($extraction) {
            $extractionData = json_decode($extraction->extraction_data, true);
            if (is_array($extractionData)) {
                foreach ($extractionData as $page) {
                    $pageNum = $page['page_number'] ?? 0;
                    $wordCount = count($page['words'] ?? []);
                    $pageDimensions[$pageNum] = [
                        'width' => (float) ($page['width'] ?? 0),
                        'height' => (float) ($page['height'] ?? 0),
                    ];
                    $pageTextCounts[$pageNum] = [
                        'original' => $wordCount,
                        'remaining' => $wordCount, // Start with all text
                        'nulled' => 0
                    ];
                }
            }
        }
        
        // Count how many text blocks are being nulled or removed per page
        foreach ($edits as $edit) {
            $pageNum = $edit['page_number'];
            $newText = trim($edit['new_text'] ?? '');
            $originalText = trim($edit['original_text'] ?? '');
            
            // If text is being removed (becomes empty when it wasn't)
            if (!empty($originalText) && empty($newText)) {
                if (isset($pageTextCounts[$pageNum])) {
                    $pageTextCounts[$pageNum]['nulled']++;
                    $pageTextCounts[$pageNum]['remaining']--;
                }
            }
        }
        
        // Check if any page is being completely nulled out (all text removed)
        $nulledPages = [];
        foreach ($pageTextCounts as $pageNum => $counts) {
            // If we have original text and are trying to remove all of it
            if ($counts['original'] > 0 && $counts['remaining'] <= 0) {
                $nulledPages[] = $pageNum;
            }
        }
        
        if (!empty($nulledPages)) {
            $pageList = implode(', ', $nulledPages);
            \Log::warning('Prevented complete page text deletion', [
                'document_id' => $document->id,
                'pages' => $nulledPages,
                'page_text_counts' => $pageTextCounts
            ]);
            
            return response()->json([
                'success' => false,
                'error' => "Cannot save: You are attempting to delete all text from page(s) {$pageList}. This operation has been blocked to prevent data loss. Please ensure at least some text remains on each page, or use the page deletion feature if you want to remove the entire page."
            ], 400);
        }

        // Hard guard: clamp incoming edit geometry to page bounds so text can never
        // be saved outside the page even if client-side constraints are bypassed.
        foreach ($edits as &$edit) {
            $pageNum = (int) ($edit['page_number'] ?? 0);
            $dims = $pageDimensions[$pageNum] ?? null;
            if (!$dims || $dims['width'] <= 0 || $dims['height'] <= 0) {
                continue;
            }
            if (!isset($edit['bbox']) || !is_array($edit['bbox']) || count($edit['bbox']) < 4) {
                continue;
            }
            $minSize = 0.5;
            $x0 = (float) ($edit['bbox'][0] ?? 0);
            $y0 = (float) ($edit['bbox'][1] ?? 0);
            $x1 = (float) ($edit['bbox'][2] ?? 0);
            $y1 = (float) ($edit['bbox'][3] ?? 0);
            $left = min($x0, $x1);
            $top = min($y0, $y1);
            $right = max($x0, $x1);
            $bottom = max($y0, $y1);

            $left = max(0.0, min($left, max(0.0, $dims['width'] - $minSize)));
            $top = max(0.0, min($top, max(0.0, $dims['height'] - $minSize)));
            $right = max($left + $minSize, min($right, $dims['width']));
            $bottom = max($top + $minSize, min($bottom, $dims['height']));

            $edit['bbox'] = [$left, $top, $right, $bottom];

            if (isset($edit['origin_x'])) {
                $edit['origin_x'] = max(0.0, min((float) $edit['origin_x'], $dims['width']));
            }
            if (isset($edit['origin_y'])) {
                $edit['origin_y'] = max(0.0, min((float) $edit['origin_y'], $dims['height']));
            }
        }
        unset($edit);

        $receivedEditsCount = count($edits);
        if (in_array($saveMode, ['targeted_save', 'live_save'], true)) {
            $droppedEdits = [];
            $edits = array_values(array_filter($edits, function ($edit) use (&$droppedEdits) {
                $isMaterial = $this->isMaterialEditForTargetedSave($edit);
                if (!$isMaterial) {
                    $droppedEdits[] = [
                        'page_number' => $edit['page_number'] ?? null,
                        'block_num' => $edit['block_num'] ?? null,
                        'original_text' => $edit['original_text'] ?? '',
                        'new_text' => $edit['new_text'] ?? '',
                    ];
                }

                return $isMaterial;
            }));

            if (!empty($droppedEdits)) {
                \Log::info($saveMode . ' dropped no-op overlay edits', [
                    'document_id' => $document->id,
                    'received_edits' => $receivedEditsCount,
                    'material_edits' => count($edits),
                    'dropped_edits' => $droppedEdits,
                ]);
            }
        }

        if (empty($edits)) {
            return response()->json([
                'success' => true,
                'message' => 'No material overlay edits to save',
            ]);
        }

        if ($saveMode === 'live_save') {
            $lastPreviewPayload = [];
            foreach ($edits as $edit) {
                $liveSave = $this->runLiveSaveScript($document, $edit, $workingCopyToken !== '' ? $workingCopyToken : null);
                if (!($liveSave['success'] ?? false)) {
                    \Log::error('live_save batch failed inside saveEdits', [
                        'document_id' => $document->id,
                        'edit' => [
                            'page_number' => $edit['page_number'] ?? null,
                            'original_text' => $edit['original_text'] ?? '',
                            'new_text' => $edit['new_text'] ?? '',
                        ],
                        'output' => $liveSave['output'] ?? null,
                        'error' => $liveSave['error'] ?? 'Live save failed.',
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to apply edits to PDF',
                        'error' => $liveSave['error'] ?? 'Live save failed.',
                    ], (int) ($liveSave['status'] ?? 500));
                }

                $lastPreviewPayload = $liveSave['preview'] ?? [];
            }

            $this->refreshOverlayExtractionArtifacts($document, $pythonBinary, (bool) ($validated['skip_refresh'] ?? false));
            $document->touch();

            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => 'Save PDF Edits',
                    'category' => 'pdf_save',
                    'details' => [
                        'edits_count' => count($edits),
                        'edited_pages' => array_values(array_unique(array_map(static fn ($edit) => (int) ($edit['page_number'] ?? 0), $edits))),
                        'save_mode' => 'live_save',
                    ],
                    'document_id' => $document->id,
                    'status' => 'success',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json(array_merge([
                'success' => true,
                'message' => 'Edits applied successfully',
                'save_mode' => 'live_save',
            ], $lastPreviewPayload));
        }

        // Use unique temp files per request to avoid permission issues when
        // stale fixed filenames are owned by another user/process.
        $tempJsonDir = storage_path('app/temp');
        if (!is_dir($tempJsonDir)) {
            @mkdir($tempJsonDir, 0775, true);
        }
        $makeTempFile = function (string $prefix) use ($tempJsonDir, $document) {
            $candidates = [$tempJsonDir, sys_get_temp_dir()];
            foreach ($candidates as $dir) {
                if (!$dir || !is_dir($dir)) {
                    continue;
                }
                if (!is_writable($dir)) {
                    continue;
                }
                $path = rtrim($dir, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR
                    . $prefix
                    . $document->id
                    . '_'
                    . uniqid('', true)
                    . '.json';
                // Reserve the path immediately so later writes are deterministic.
                if (@file_put_contents($path, '') !== false) {
                    return $path;
                }
            }
            throw new \RuntimeException('Failed to allocate temporary JSON file path.');
        };

        // Save edits to temporary file
        $editsFile = $makeTempFile('edits_');
        if (@file_put_contents($editsFile, json_encode($edits)) === false) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to write edits temp file.',
            ], 500);
        }

        // Track which pages have edits
        $editedPages = [];
        foreach ($edits as $edit) {
            $pageNum = $edit['page_number'];
            if (!in_array($pageNum, $editedPages)) {
                $editedPages[] = $pageNum;
            }
        }
        sort($editedPages);
        
        \Log::info('Pages with edits', [
            'document_id' => $document->id,
            'edited_pages' => $editedPages,
            'total_edits' => count($edits),
            'received_edits' => $receivedEditsCount,
        ]);

        $fullPath = Storage::path($document->path);
        $isNewSaveMode = $saveMode === 'new_save_mode';
        $isSurgicalSaveMode = $saveMode === 'surgical_save';
        $isTargetedSaveMode = $saveMode === 'targeted_save';
        $allPartialBlockEdits = !empty($edits) && collect($edits)->every(function ($edit) {
            return !empty($edit['preserve_partial_block']);
        });
        $allEditsSupportStrictSurgical = !empty($edits) && collect($edits)->every(function ($edit) {
            $bbox = $edit['bbox'] ?? null;
            $originalBbox = $edit['original_bbox'] ?? null;

            return isset($edit['page_number'])
                && array_key_exists('original_text', $edit)
                && array_key_exists('new_text', $edit)
                && is_array($bbox)
                && count($bbox) >= 4
                && is_array($originalBbox)
                && count($originalBbox) >= 4;
        });
        $useStrictSurgicalSave = $isNewSaveMode || $isSurgicalSaveMode || $allPartialBlockEdits;

        // OPTIMIZATION: If no edits made, skip save pipeline entirely.
        if (empty($editedPages)) {
            \Log::info('No edits detected, skipping PDF save', [
                'document_id' => $document->id,
                'save_mode' => $saveMode,
            ]);
            if (file_exists($editsFile)) {
                @unlink($editsFile);
            }
            return response()->json([
                'success' => true,
                'message' => 'No changes to save.'
            ]);
        }

        $extractionFile = null;
        $editedPagesFile = null;

        // CRITICAL: Create a backup of the PDF before applying destructive edits
        // This allows recovery if the edit process corrupts or loses content
        $backupPath = Storage::path('documents/backup_' . pathinfo($document->path, PATHINFO_FILENAME) . '.pdf');
        if (file_exists($fullPath)) {
            copy($fullPath, $backupPath);
            \Log::info('Created pre-edit backup', [
                'document_id' => $document->id,
                'backup_path' => $backupPath,
            ]);
        }

        $output = [];
        $returnCode = 0;

        if ($isTargetedSaveMode) {
            $pythonScript = base_path('python/pdf-editor/apply_pdf_edits_targeted.py');
            $command = sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($pythonScript),
                escapeshellarg($fullPath),
                escapeshellarg($editsFile)
            );

            \Log::info('Applying PDF edits (targeted_save)', [
                'document_id' => $document->id,
                'pipeline' => 'targeted_save',
                'pdf_path' => $fullPath,
                'backup_path' => $backupPath,
                'received_edits' => $receivedEditsCount,
                'material_edits' => count($edits),
                'edited_pages' => $editedPages,
                'configured_save_mode' => $saveMode,
            ]);

            exec($command, $output, $returnCode);
        } elseif ($useStrictSurgicalSave) {
            // New save mode: strict in-place surgical edits only.
            // This path never rebuilds full pages and will abort if the
            // script cannot confidently locate the exact old text to remove.
            $pythonScript = base_path('python/pdf-editor/apply_pdf_edits.py');
            $command = sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($pythonScript),
                escapeshellarg($fullPath),
                escapeshellarg($editsFile)
            );

            \Log::info('Applying PDF edits (strict surgical)', [
                'document_id' => $document->id,
                'pipeline' => $allPartialBlockEdits
                    ? 'partial_block_surgical'
                    : ($isSurgicalSaveMode ? 'configured_surgical_save' : 'strict_surgical'),
                'pdf_path' => $fullPath,
                'backup_path' => $backupPath,
                'edits_count' => count($edits),
                'edited_pages' => $editedPages,
                'all_partial_block_edits' => $allPartialBlockEdits,
                'configured_save_mode' => $saveMode,
                'all_edits_support_strict_surgical' => $allEditsSupportStrictSurgical,
            ]);

            exec($command, $output, $returnCode);
        } else {
            // Full-page rebuild mode.
            // For each edited page: clear ALL text, then redraw everything from
            // extraction (deletions are simply not redrawn — no targeted bbox
            // redaction, no risk of collateral text loss).
            $pythonScript = base_path('python/pdf-editor/rebuild_pdf_from_overlay_extraction.py');
            $extractScript = base_path('python/pdf-editor/extract_pdf_pymupdf.py');
            $cleanPath = Storage::path('temp/clean_' . $document->id . '.pdf');

            if (!file_exists($cleanPath)) {
                if (file_exists($editsFile)) @unlink($editsFile);
                return response()->json([
                    'success' => false,
                    'message' => 'Clean PDF base not found. Please reload the document and try again.',
                ], 500);
            }

            // Fetch extraction data from DB (needed to redraw unchanged words).
            $userEmail = $this->resolveEditorEmail();
            $sessionId = session()->getId();
            $extractionRow = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);

            if (!$extractionRow) {
                if (file_exists($editsFile)) @unlink($editsFile);
                return response()->json([
                    'success' => false,
                    'message' => 'No extraction data found. Please reload the document and try again.',
                ], 500);
            }

            $extractionFile = $makeTempFile('extraction_');
            if (@file_put_contents($extractionFile, $extractionRow->extraction_data) === false) {
                if (file_exists($editsFile)) @unlink($editsFile);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to write extraction temp file.',
                ], 500);
            }

            $editedPagesFile = $makeTempFile('pages_');
            if (@file_put_contents($editedPagesFile, json_encode($editedPages)) === false) {
                if (file_exists($extractionFile)) @unlink($extractionFile);
                if (file_exists($editsFile)) @unlink($editsFile);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to write edited-pages temp file.',
                ], 500);
            }

            $command = sprintf(
                '%s %s --fullpage %s %s %s %s %s %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($pythonScript),
                escapeshellarg($cleanPath),
                escapeshellarg('@' . $extractionFile),
                escapeshellarg('@' . $editsFile),
                escapeshellarg($fullPath),
                escapeshellarg('@' . $editedPagesFile),
                escapeshellarg(Storage::path($document->original_backup_path))
            );

            \Log::info('Applying PDF edits (full_page_save / fullpage rebuild)', [
                'document_id' => $document->id,
                'pdf_path' => $fullPath,
                'clean_path' => $cleanPath,
                'backup_path' => $backupPath,
                'original_backup_path' => Storage::path($document->original_backup_path),
                'edits_count' => count($edits),
                'edited_pages' => $editedPages,
                'configured_save_mode' => $saveMode,
            ]);

            exec($command, $output, $returnCode);
        }
        
        // Log the output
        \Log::info('Python script output', [
            'return_code' => $returnCode,
            'save_mode' => $saveMode,
            'output' => implode("\n", $output)
        ]);

        if ($extractionFile && file_exists($extractionFile)) {
            unlink($extractionFile);
        }
        if ($editedPagesFile && file_exists($editedPagesFile)) {
            unlink($editedPagesFile);
        }
        if (file_exists($editsFile)) {
            @unlink($editsFile);
        }

        if ($returnCode === 0) {
            $this->refreshOverlayExtractionArtifacts($document, $pythonBinary, (bool) ($validated['skip_refresh'] ?? false));
        }

        if ($returnCode !== 0) {
            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => 'Save PDF Edits',
                    'category' => 'pdf_save',
                    'details' => [
                        'edits_count' => count($edits),
                        'edited_pages' => $editedPages,
                        'error' => implode("\n", $output),
                    ],
                    'document_id' => $document->id,
                    'status' => 'failed',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }
            return response()->json([
                'success' => false,
                'message' => 'Failed to apply edits to PDF',
                'error' => implode("\n", $output)
            ], 500);
        }

        $document->touch();

        if (Auth::check()) {
            UserActivity::create([
                'user_id' => Auth::id(),
                'action' => 'Save PDF Edits',
                'category' => 'pdf_save',
                'details' => [
                    'edits_count' => count($edits),
                    'edited_pages' => $editedPages,
                ],
                'document_id' => $document->id,
                'status' => 'success',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Edits applied successfully',
            'debug_output' => implode("\n", $output)
        ]);
    }

    public function saveImage(Request $request, Document $document)
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:20480'],
        ]);

        $file = $request->file('image');
        $fullPath = Storage::path($document->path);

        // Write the uploaded image directly over the existing document file
        file_put_contents($fullPath, file_get_contents($file->getPathname()));

        $document->update([
            'mime_type' => $file->getClientMimeType() ?: 'image/png',
            'size_bytes' => $file->getSize(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Image saved successfully.',
        ]);
    }

    public function createWorkingCopySnapshot(Request $request, Document $document)
    {
        try {
            $snapshot = $this->createLiveSaveSnapshot($document);
        } catch (\Throwable $e) {
            Log::error('Failed to create working-copy snapshot', [
                'document_id' => $document->id,
                'path' => $document->path,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create working-copy snapshot.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'snapshot_token' => $snapshot['token'],
        ]);
    }

    public function restoreWorkingCopy(Request $request, Document $document)
    {
        $request->validate([
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:51200'],
            'snapshot_token' => ['nullable', 'string'],
            'discard_preview_entries' => ['nullable', 'array'],
            'discard_preview_entries.*' => ['nullable', 'string'],
            'delete_snapshot' => ['nullable', 'boolean'],
        ]);

        $fullPath = Storage::path($document->path);
        $snapshotToken = trim((string) $request->input('snapshot_token', ''));
        $sourcePath = null;

        if ($request->hasFile('pdf')) {
            $file = $request->file('pdf');
            $sourcePath = $file?->getPathname();
        } elseif ($snapshotToken !== '') {
            $snapshotPath = $this->liveSaveSnapshotPath($document, $snapshotToken);
            if (!is_file($snapshotPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Working-copy snapshot not found.',
                ], 404);
            }
            $sourcePath = $snapshotPath;
        } else {
            return response()->json([
                'success' => false,
                'message' => 'No working-copy restore source was provided.',
            ], 422);
        }

        file_put_contents($fullPath, file_get_contents($sourcePath));

        $discardPreviewEntries = $request->input('discard_preview_entries', []);
        if (is_array($discardPreviewEntries) && !empty($discardPreviewEntries)) {
            $this->discardLiveSavePreviewEntries($document, $discardPreviewEntries);
        }

        if ($snapshotToken !== '' && (bool) $request->boolean('delete_snapshot')) {
            $snapshotPath = $this->liveSaveSnapshotPath($document, $snapshotToken);
            if (is_file($snapshotPath)) {
                @unlink($snapshotPath);
            }
        }

        $this->invalidateCleanPdf($document);
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
        $this->refreshOverlayExtractionArtifacts($document, $pythonBinary, false);
        $document->touch();

        return response()->json([
            'success' => true,
            'message' => 'Working PDF restored successfully.',
        ]);
    }

    public function discardWorkingCopySnapshot(Request $request, Document $document)
    {
        $request->validate([
            'snapshot_token' => ['required', 'string'],
            'discard_preview_entries' => ['nullable', 'array'],
            'discard_preview_entries.*' => ['nullable', 'string'],
        ]);

        $snapshotToken = trim((string) $request->input('snapshot_token', ''));
        $snapshotPath = $this->liveSaveSnapshotPath($document, $snapshotToken);
        if (is_file($snapshotPath)) {
            @unlink($snapshotPath);
        }

        $discardPreviewEntries = $request->input('discard_preview_entries', []);
        if (is_array($discardPreviewEntries) && !empty($discardPreviewEntries)) {
            $this->discardLiveSavePreviewEntries($document, $discardPreviewEntries);
        }

        return response()->json([
            'success' => true,
            'message' => 'Working-copy snapshot discarded.',
        ]);
    }

    public function cleanPdf(Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
        $cleanPath = $this->ensureCleanPdfPath(
            $document,
            $pythonBinary,
            $this->resolveEditorEmail(),
            session()->getId()
        );

        if (!$cleanPath || !file_exists($cleanPath)) {
            return response()->json(['error' => 'Clean PDF not found'], 404);
        }

        return response()->file($cleanPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="clean.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Live save — surgically patch a single overlay-editor field into the PDF
     * using an incremental save.  No page refresh; returns JSON success/fail.
     */
    public function liveSave(Request $request, Document $document)
    {
        $validated = $request->validate([
            'page_number'   => ['required', 'integer', 'min:1'],
            'original_text' => ['present', 'string'],
            'new_text'      => ['nullable'],
            'original_bbox' => ['required', 'array', 'min:4'],
            'bbox'          => ['nullable', 'array'],
            'origin_x'      => ['nullable', 'numeric'],
            'origin_y'      => ['nullable', 'numeric'],
            'font'          => ['required', 'string'],
            'font_size'     => ['required', 'numeric'],
            'font_weight'   => ['nullable', 'string'],
            'font_style'    => ['nullable', 'string'],
            'font_xref'     => ['nullable', 'integer'],
            'underline'     => ['nullable', 'boolean'],
            'line_height'   => ['nullable', 'numeric'],
            'color'         => ['nullable', 'string'],
            'background_color' => ['nullable', 'string'],
            'word_styles'   => ['nullable', 'array'],
            'line_metrics'  => ['nullable', 'array'],
            'source_content_ops' => ['nullable', 'array'],
            'synthetic_textbox' => ['nullable', 'boolean'],
            'retry_mode'    => ['nullable', 'string'],
            'redaction_padding' => ['nullable', 'numeric'],
            'working_copy_token' => ['nullable', 'string'],
        ]);

        $rawNewText = $request->input('new_text', '');
        if (is_array($rawNewText) || is_object($rawNewText)) {
            return response()->json([
                'success' => false,
                'error' => 'The new text field must be a string.',
            ], 422);
        }
        $validated['new_text'] = $rawNewText === null ? '' : (string) $rawNewText;
        $validated['word_styles'] = is_array($request->input('word_styles')) ? $request->input('word_styles') : null;
        $validated['line_metrics'] = is_array($request->input('line_metrics')) ? $request->input('line_metrics') : null;
        $validated['source_content_ops'] = is_array($request->input('source_content_ops')) ? $request->input('source_content_ops') : null;
        $validated['retry_mode'] = is_string($request->input('retry_mode')) ? $request->input('retry_mode') : null;
        $validated['redaction_padding'] = is_numeric($request->input('redaction_padding'))
            ? (float) $request->input('redaction_padding')
            : null;
        $workingCopyToken = is_string($request->input('working_copy_token')) ? trim((string) $request->input('working_copy_token')) : '';

        if (!$this->isMaterialEditForTargetedSave($validated)) {
            return response()->json([
                'success' => true,
                'skipped_noop' => true,
                'message' => 'No material live-save changes detected.',
            ]);
        }

        $liveSave = $this->runLiveSaveScript($document, $validated, $workingCopyToken !== '' ? $workingCopyToken : null);
        if (!($liveSave['success'] ?? false)) {
            \Log::error('live_save failed', [
                'document_id' => $document->id,
                'exit_code' => $liveSave['exit_code'] ?? null,
                'output' => $liveSave['output'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'error' => $liveSave['error'] ?? 'Live save failed.',
            ], (int) ($liveSave['status'] ?? 500));
        }

        return response()->json(array_merge([
            'success' => true,
        ], $liveSave['preview'] ?? []));
    }

    public function getFonts(Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        // Use the path column like other methods
        $pdfPath = Storage::path($document->path);
        
        if (!file_exists($pdfPath)) {
            return response()->json(['error' => 'PDF not found'], 404);
        }
        
        // Run Python script to extract fonts
        $pythonScript = base_path('python/pdf-editor/extract_pdf_fonts.py');
        $command = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($pdfPath)
        );
        
        $output = shell_exec($command);
        $result = json_decode($output, true);
        
        if (!$result || isset($result['error'])) {
            return response()->json(['error' => $result['error'] ?? 'Failed to extract fonts'], 500);
        }
        
        return response()->json($result);
    }

    public function matchFonts(Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        // Get the PyMuPDF extraction data to analyze fonts
        $fitzExtraction = $document->pdfExtractionsFitz()->latest()->first();
        
        if (!$fitzExtraction || !$fitzExtraction->extraction_data) {
            return response()->json([
                'success' => false,
                'message' => 'No extraction data found. Please run PyMuPDF extraction first.'
            ], 404);
        }

        // Save extraction data to a temporary file
        $tempJsonPath = storage_path('app/temp_extraction_' . $document->id . '.json');
        file_put_contents($tempJsonPath, json_encode($fitzExtraction->extraction_data));

        // Output CSS path in storage (writable), then we'll symlink or serve it
        $outputCssPath = storage_path('app/public/loaded_fonts.css');
        
        // Ensure storage/app/public directory exists
        if (!file_exists(storage_path('app/public'))) {
            mkdir(storage_path('app/public'), 0755, true);
        }

        // Run install_fonts.py script with output CSS path
        $pythonScript = base_path('python/pdf-editor/install_fonts.py');
        $command = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($tempJsonPath),
            escapeshellarg($outputCssPath)
        );
        
        $output = shell_exec($command);
        
        // Log the full output for debugging
        \Log::info('Font matching output:', ['output' => $output]);
        
        // Extract JSON from output (last line should be JSON)
        $lines = explode("\n", trim($output));
        $jsonLine = end($lines);
        $result = json_decode($jsonLine, true);
        
        // Clean up temp file
        if (file_exists($tempJsonPath)) {
            unlink($tempJsonPath);
        }
        
        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to parse font matching output',
                'output' => $output,
                'raw_json_line' => $jsonLine
            ], 500);
        }
        
        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
                'output' => $output
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'loaded_fonts' => $result['loaded_fonts'] ?? 0,
            'total_fonts' => $result['total_fonts'] ?? 0,
            'font_results' => $result['font_results'] ?? [],
            'message' => 'Font matching completed',
            'css_url' => route('loadedFonts') . '?t=' . time()
        ]);
    }

    public function reorderPages(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'page_order' => ['required', 'array'],
            'page_order.*' => ['required', 'integer', 'min:0'],
            'session_id' => ['nullable', 'string'],
        ]);

        $pageOrder = $validated['page_order'];
        $sessionId = $validated['session_id'] ?? null;
        $inputPath = Storage::path($document->path);
        
        // Generate output path for reordered PDF
        $tempOutputPath = Storage::path('documents/temp_reorder_' . Str::uuid() . '.pdf');
        
        // Call Python script to reorder pages
        $pythonScript = base_path('python/pdf-editor/reorder_pdf_pages.py');
        $pageOrderStr = implode(',', $pageOrder);
        
        $command = sprintf(
            '%s %s %s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($inputPath),
            escapeshellarg($tempOutputPath),
            escapeshellarg($pageOrderStr)
        );
        
        exec($command, $output, $returnCode);
        $output = implode("\n", $output);
        
        // Parse JSON response from Python script
        $result = json_decode($output, true);
        
        if (!$result || !isset($result['success'])) {
            // Clean up temp file if it exists
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to parse reorder output',
                'output' => $output
            ], 500);
        }
        
        if (!$result['success']) {
            // Clean up temp file if it exists
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }
            
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Unknown error occurred',
                'output' => $output
            ], 500);
        }
        
        // Replace the original PDF with the reordered one
        if (file_exists($tempOutputPath)) {
            // Backup original (optional)
            $backupPath = Storage::path('documents/backup_' . basename($document->path));
            copy($inputPath, $backupPath);
            
            // Get original page count to detect deleted pages
            $pdf = new \finfo(FILEINFO_MIME_TYPE);
            $pdfInfo = shell_exec(sprintf('pdfinfo %s 2>&1 | grep "Pages:"', escapeshellarg($inputPath)));
            $originalPageCount = 0;
            if (preg_match('/Pages:\s+(\d+)/', $pdfInfo, $matches)) {
                $originalPageCount = (int)$matches[1];
            }
            
            // Detect which pages were deleted (not in page_order)
            $deletedPages = [];
            if ($originalPageCount > 0) {
                $allPages = range(0, $originalPageCount - 1);
                $deletedPages = array_diff($allPages, $pageOrder);
            }
            
            // Delete annotations for deleted pages if session_id provided
            if ($sessionId && !empty($deletedPages)) {
                foreach ($deletedPages as $deletedPage) {
                    $deletedCount = PdfState::where('document_id', $document->id)
                        ->where('session_id', $sessionId)
                        ->where('page_number', $deletedPage)
                        ->delete();
                    
                    if ($deletedCount > 0) {
                        \Log::info("Deleted {$deletedCount} annotations for page {$deletedPage}", [
                            'document_id' => $document->id,
                            'session_id' => $sessionId,
                            'page' => $deletedPage
                        ]);
                    }
                }
            }
            
            // Replace with reordered PDF
            copy($tempOutputPath, $inputPath);
            
            // Clean up temp file
            unlink($tempOutputPath);
            
            // Always re-extract the PDF after page reordering to update extraction data
            \Log::info('Re-extracting PDF after reorder', [
                'document_id' => $document->id,
                'deleted_pages' => $deletedPages,
                'new_page_count' => count($pageOrder)
            ]);
            
            $pythonScript = base_path('python/pdf-editor/extract_pdf_pymupdf.py');
            $userEmail = $this->resolveEditorEmail();
            $currentSessionId = session()->getId();
            
            $extractCommand = sprintf(
                '%s %s %s %d %s %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($pythonScript),
                escapeshellarg($inputPath),
                $document->id,
                escapeshellarg($userEmail),
                escapeshellarg($currentSessionId)
            );
            
            exec($extractCommand, $extractOutput, $extractReturnCode);
            
            if ($extractReturnCode === 0) {
                \Log::info('Re-extraction completed successfully', [
                    'document_id' => $document->id
                ]);
            } else {
                \Log::error('Failed to re-extract PDF after reordering', [
                    'document_id' => $document->id,
                    'return_code' => $extractReturnCode,
                    'output' => implode("\n", $extractOutput)
                ]);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Pages reordered successfully',
                'total_pages' => $result['total_pages'] ?? count($pageOrder),
                'deleted_pages' => array_values($deletedPages),
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Reordered PDF file not found'
        ], 500);
    }

    public function addBlankPage(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'insert_after' => ['nullable', 'integer'],
            'size_reference' => ['nullable', 'integer'],
        ]);

        $insertAfter = $validated['insert_after'] ?? -1;
        $sizeReference = $validated['size_reference'] ?? -1;

        $inputPath = Storage::path($document->path);
        $tempOutputPath = Storage::path('documents/temp_add_page_' . Str::uuid() . '.pdf');

        $pythonScript = base_path('python/pdf-editor/add_blank_page.py');

        $command = sprintf(
            '%s %s %s %s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($inputPath),
            escapeshellarg($tempOutputPath),
            escapeshellarg((string) $insertAfter),
            escapeshellarg((string) $sizeReference)
        );

        exec($command, $output, $returnCode);
        $outputStr = implode("\n", $output);

        // Try to find JSON in the output (last line should be JSON)
        $lines = array_filter($output, function($line) {
            return !empty(trim($line));
        });
        $jsonLine = end($lines);
        
        $result = json_decode($jsonLine, true);

        if (!$result || !isset($result['success'])) {
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }

            \Log::error('Add blank page - Failed to parse output', [
                'output' => $outputStr,
                'json_line' => $jsonLine,
                'return_code' => $returnCode
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to parse add-page output',
                'output' => $outputStr,
                'debug' => [
                    'json_line' => $jsonLine,
                    'return_code' => $returnCode
                ]
            ], 500);
        }

        if (!$result['success']) {
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }

            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Unknown error occurred',
                'output' => $outputStr
            ], 500);
        }

        if (file_exists($tempOutputPath)) {
            $backupPath = Storage::path('documents/backup_' . basename($document->path));
            copy($inputPath, $backupPath);
            copy($tempOutputPath, $inputPath);
            unlink($tempOutputPath);
            
            // Re-extract the PDF to update extraction data after adding page
            $pythonScript = base_path('python/pdf-editor/extract_pdf_pymupdf.py');
            $userEmail = $this->resolveEditorEmail();
            $currentSessionId = session()->getId();
            
            $extractCommand = sprintf(
                '%s %s %s %d %s %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($pythonScript),
                escapeshellarg($inputPath),
                $document->id,
                escapeshellarg($userEmail),
                escapeshellarg($currentSessionId)
            );
            
            exec($extractCommand, $extractOutput, $extractReturnCode);
            
            if ($extractReturnCode !== 0) {
                \Log::warning('Failed to re-extract PDF after adding blank page', [
                    'document_id' => $document->id,
                    'output' => implode("\n", $extractOutput)
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Blank page added successfully',
                'total_pages' => $result['total_pages'] ?? null
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Updated PDF file not found'
        ], 500);
    }
    
    public function rotatePage(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        try {
            \Log::info('Rotate page request', [
                'document_id' => $document->id,
                'request_data' => $request->all()
            ]);
            
            $validated = $request->validate([
                'page_number' => ['required', 'integer', 'min:1'],
                'rotation' => ['nullable', 'integer', 'in:90,180,270,-90'],
            ]);
        } catch (\Exception $e) {
            \Log::error('Rotation validation failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validation error: ' . $e->getMessage()
            ], 500);
        }

        $pageNumber = $validated['page_number'];
        $rotation = $validated['rotation'] ?? 90;

        $inputPath = Storage::path($document->path);
        $tempOutputPath = Storage::path('documents/temp_rotate_page_' . Str::uuid() . '.pdf');

        $pythonScript = base_path('python/pdf-editor/rotate_pdf_page.py');

        $command = sprintf(
            '%s %s %s %s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($inputPath),
            escapeshellarg($tempOutputPath),
            escapeshellarg((string) $pageNumber),
            escapeshellarg((string) $rotation)
        );

        exec($command, $output, $returnCode);
        $outputStr = implode("\n", $output);

        // Check for SUCCESS message
        $success = false;
        foreach ($output as $line) {
            if (strpos($line, 'SUCCESS:') === 0) {
                $success = true;
                break;
            }
        }

        if (!$success || $returnCode !== 0) {
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }
            
            // Check if error message indicates corruption
            $errorMessage = 'Failed to rotate page';
            if (strpos($outputStr, 'corrupted') !== false) {
                $errorMessage = 'PDF file is corrupted. Please re-upload or use a different document.';
            }

            return response()->json([
                'success' => false,
                'message' => $errorMessage,
                'output' => $outputStr
            ], 500);
        }

        if (file_exists($tempOutputPath)) {
            $backupPath = Storage::path('documents/backup_' . basename($document->path));
            copy($inputPath, $backupPath);
            copy($tempOutputPath, $inputPath);
            unlink($tempOutputPath);
            
            // Re-extract the PDF to update extraction data after rotation
            $pythonScript = base_path('python/pdf-editor/extract_pdf_pymupdf.py');
            $userEmail = $this->resolveEditorEmail();
            $currentSessionId = session()->getId();
            
            $extractCommand = sprintf(
                '%s %s %s %d %s %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($pythonScript),
                escapeshellarg($inputPath),
                $document->id,
                escapeshellarg($userEmail),
                escapeshellarg($currentSessionId)
            );
            
            exec($extractCommand, $extractOutput, $extractReturnCode);
            
            if ($extractReturnCode !== 0) {
                \Log::warning('Failed to re-extract PDF after rotating page', [
                    'document_id' => $document->id,
                    'output' => implode("\n", $extractOutput)
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Page rotated successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Rotated PDF file not found'
        ], 500);
    }
    
    /**
     * Take a screenshot of the document edit page
     */
    public function takeScreenshot(Request $request, Document $document)
    {
        $validated = $request->validate([
            'suffix' => ['nullable', 'string', 'in:before,after'],
            'page' => ['nullable', 'integer', 'min:1'],
            'edits' => ['nullable', 'array'],
            'url_type' => ['nullable', 'string', 'in:edit,overlay'], // New field
        ]);
        
        $suffix = $validated['suffix'] ?? null;
        $page = $validated['page'] ?? 1;
        $edits = $validated['edits'] ?? [];
        $urlType = $validated['url_type'] ?? 'edit';
        
        // Build the URL - use host.docker.internal if running in Docker, otherwise localhost
        // This allows the headless browser inside the container to reach the Laravel app
        $baseUrl = env('APP_URL', 'http://localhost:8081');
        // If running in Docker, the browser needs to access the host machine
        if (file_exists('/.dockerenv')) {
            $baseUrl = 'http://host.docker.internal:8081';
        }
        
        // Choose URL based on url_type
        $url = $urlType === 'overlay' 
            ? "{$baseUrl}/documents/{$document->id}/overlay-editor"
            : "{$baseUrl}/documents/{$document->id}/edit";
        
        // Path to Python script and venv
        $pythonVenv = base_path('python/venv/bin/python');
        $pythonScript = base_path('python/test_helpers/screenshot_document.py');
        $playwrightPath = base_path('python/.playwright');
        
        // Build command with PLAYWRIGHT_BROWSERS_PATH set
        $command = sprintf(
            'PLAYWRIGHT_BROWSERS_PATH=%s %s %s %d --full-url %s --page %d%s 2>&1',
            escapeshellarg($playwrightPath),
            escapeshellarg($pythonVenv),
            escapeshellarg($pythonScript),
            $document->id,
            escapeshellarg($url),
            $page,
            $suffix ? ' --suffix ' . escapeshellarg($suffix) : ''
        );
        
        \Log::info('Taking screenshot', [
            'document_id' => $document->id,
            'suffix' => $suffix,
            'command' => $command
        ]);
        
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        \Log::info('Screenshot result', [
            'return_code' => $returnCode,
            'output' => implode("\n", $output)
        ]);
        
        if ($returnCode === 0) {
            // Build the screenshot filename
            $filename = $suffix 
                ? "{$document->id}_page_{$page}_{$suffix}.png"
                : "{$document->id}_page_{$page}.png";
            
            $screenshotPath = base_path("python/screenshots/{$filename}");
            
            // Save edit coordinates alongside screenshot for verification
            if (!empty($edits)) {
                $editsFilename = $suffix 
                    ? "{$document->id}_page_{$page}_{$suffix}_edits.json"
                    : "{$document->id}_page_{$page}_edits.json";
                $editsPath = base_path("python/screenshots/{$editsFilename}");
                file_put_contents($editsPath, json_encode($edits, JSON_PRETTY_PRINT));
                
                \Log::info('Saved edit coordinates for verification', [
                    'document_id' => $document->id,
                    'edits_file' => $editsFilename,
                    'edit_count' => count($edits)
                ]);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Screenshot taken successfully',
                'filename' => $filename,
                'path' => "python/screenshots/{$filename}",
                'edits_saved' => !empty($edits),
                'edit_count' => count($edits)
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to take screenshot',
            'output' => implode("\n", $output)
        ], 500);
    }

    public function saveAnnotations(Request $request, Document $document)
    {
        $validated = $request->validate([
            'annotations' => 'required|array',
            'session_id' => 'required|string',
            'user_email' => 'nullable|email',
            'annotation_id' => 'nullable|string',
            'state' => 'nullable|string|in:saved,not_saved,deleted',
        ]);

        $annotationsPayload = $request->input('annotations', []);
        if (!is_array($annotationsPayload)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid annotations payload.',
            ], 422);
        }

        $annotationsPayload = $this->normalizeAnnotationsForPersistence($document, $annotationsPayload);

        $sessionId = $validated['session_id'];
        $userEmail = $validated['user_email'] ?? null;
        $annotationId = $validated['annotation_id'] ?? null;
        
        // If annotation_id is provided, update/create that specific annotation
        if ($annotationId) {
            $annotation = is_array($annotationsPayload[0] ?? null) ? $annotationsPayload[0] : null;
            if (!$annotation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing annotation payload.',
                ], 422);
            }
            
            // Try to find existing annotation by annotation ID
            $existingAnnotation = PdfState::where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->whereRaw("JSON_EXTRACT(annotation_data, '$.id') = ?", [$annotationId])
                ->first();
            
            if ($existingAnnotation) {
                // Update existing annotation
                $existingAnnotation->update([
                    'annotation_data' => $annotation,
                    'user_email' => $userEmail,
                    'page_number' => $annotation['pageIndex'] ?? null,
                    'state' => 'not_saved',
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Annotation updated',
                    'updated' => true,
                    'annotation' => $this->annotationAssets()->enrichForClient($annotation),
                ]);
            } else {
                // Create new annotation
                PdfState::create([
                    'document_id' => $document->id,
                    'user_email' => $userEmail,
                    'session_id' => $sessionId,
                    'page_number' => $annotation['pageIndex'] ?? null,
                    'annotation_data' => $annotation,
                    'state' => 'not_saved',
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Annotation created',
                    'created' => true,
                    'annotation' => $this->annotationAssets()->enrichForClient($annotation),
                ]);
            }
        }
        
        // Bulk save - update or create each annotation by ID
        $savedCount = 0;
        $updatedCount = 0;
        $createdCount = 0;
        $targetState = $validated['state'] ?? 'not_saved';
        
        \Log::info("Bulk save annotations", [
            'document_id' => $document->id,
            'session_id' => $sessionId,
            'annotation_count' => count($annotationsPayload),
            'target_state' => $targetState
        ]);
        
        foreach ($annotationsPayload as $annotation) {
            if (!is_array($annotation)) {
                continue;
            }
            $annotationId = $annotation['id'] ?? null;
            
            if ($annotationId) {
                // Try to find and update existing annotation
                $existingAnnotation = PdfState::where('document_id', $document->id)
                    ->where('session_id', $sessionId)
                    ->whereRaw("JSON_EXTRACT(annotation_data, '$.id') = ?", [$annotationId])
                    ->first();
                
                if ($existingAnnotation) {
                    $existingAnnotation->update([
                        'annotation_data' => $annotation,
                        'user_email' => $userEmail,
                        'page_number' => $annotation['pageIndex'] ?? null,
                        'state' => $targetState,
                    ]);
                    $savedCount++;
                    $updatedCount++;
                    \Log::info("Updated annotation", ['id' => $annotationId, 'state' => $targetState]);
                    continue;
                }
            }
            
            // Create new annotation if not found
            PdfState::create([
                'document_id' => $document->id,
                'user_email' => $userEmail,
                'session_id' => $sessionId,
                'page_number' => $annotation['pageIndex'] ?? null,
                'annotation_data' => $annotation,
                'state' => $targetState,
            ]);
            $savedCount++;
            $createdCount++;
            \Log::info("Created annotation", ['id' => $annotationId ?? 'no-id', 'state' => $targetState]);
        }

        return response()->json([
            'success' => true,
            'message' => "Saved {$savedCount} annotations (updated: {$updatedCount}, created: {$createdCount})",
            'count' => $savedCount,
            'updated' => $updatedCount,
            'created' => $createdCount,
            'state' => $targetState,
        ]);
    }

    private function resolveSavedAnnotationRecords(Document $document, string $requestedSessionId = '', bool $excludeMaterialized = false): array
    {
        $fetchAnnotationsForSession = function (string $sessionId) use ($document, $excludeMaterialized) {
            $query = PdfState::query()
                ->where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->where('state', '!=', 'deleted')
                ->where('state', '!=', 'extracted')
                ->orderBy('page_number')
                ->orderBy('updated_at');

            if ($excludeMaterialized) {
                $query->where('state', '!=', 'materialized');
            }

            return $query->get();
        };

        $resolvedSessionId = '';
        $records = collect();

        if ($requestedSessionId !== '') {
            $records = $fetchAnnotationsForSession($requestedSessionId);
            if ($records->isNotEmpty()) {
                $resolvedSessionId = $requestedSessionId;
            }
        }

        if ($records->isEmpty()) {
            $latestSessionQuery = PdfState::query()
                ->where('document_id', $document->id)
                ->where('state', '!=', 'deleted')
                ->where('state', '!=', 'extracted')
                ->orderByDesc('updated_at');

            if ($excludeMaterialized) {
                $latestSessionQuery->where('state', '!=', 'materialized');
            }

            $latestSessionId = (string) ($latestSessionQuery->value('session_id') ?? '');

            if ($latestSessionId !== '') {
                $records = $fetchAnnotationsForSession($latestSessionId);
                $resolvedSessionId = $latestSessionId;
            }
        }

        return [$resolvedSessionId, $records];
    }

    public function getSavedAnnotations(Request $request, Document $document)
    {
        $validated = $request->validate([
            'session_id' => 'nullable|string',
        ]);

        $requestedSessionId = is_string($validated['session_id'] ?? null)
            ? trim((string) $validated['session_id'])
            : '';
        [$resolvedSessionId, $records] = $this->resolveSavedAnnotationRecords($document, $requestedSessionId, true);
        $deletedPromotedSourceKeys = $this->mergeDeletedPromotedSourceKeys(
            $document,
            $resolvedSessionId !== '' ? $resolvedSessionId : $requestedSessionId,
            [],
            []
        );
        $annotationAssets = $this->annotationAssets();

        $annotations = $records->map(function (PdfState $record) use ($annotationAssets) {
            $annotation = is_array($record->annotation_data)
                ? $annotationAssets->enrichForClient($record->annotation_data)
                : [];
            $annotation['db_state'] = $record->state;
            $annotation['db_updated_at'] = optional($record->updated_at)?->toIso8601String();
            return $annotation;
        })->values();

        return response()->json([
            'success' => true,
            'session_id' => $resolvedSessionId !== '' ? $resolvedSessionId : null,
            'count' => $annotations->count(),
            'annotations' => $annotations,
            'deleted_promoted_source_keys' => array_values($deletedPromotedSourceKeys),
        ]);
    }

    public function listSavedPdfOptions(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'nullable|string',
        ]);

        $requestedSessionId = is_string($validated['session_id'] ?? null)
            ? trim((string) $validated['session_id'])
            : '';
        $editorEmail = $this->resolveEditorEmail('');

        $query = PdfState::query()
            ->with(['document:id,original_name,path'])
            ->select(['id', 'document_id', 'user_email', 'session_id', 'state', 'updated_at'])
            ->where('state', '!=', 'deleted')
            ->where('state', '!=', 'extracted');

        if ($editorEmail !== '' && $requestedSessionId !== '') {
            $query->where(function ($scopedQuery) use ($editorEmail, $requestedSessionId) {
                $scopedQuery->where('user_email', $editorEmail)
                    ->orWhere('session_id', $requestedSessionId);
            });
        } elseif ($editorEmail !== '') {
            $query->where('user_email', $editorEmail);
        } elseif ($requestedSessionId !== '') {
            $query->where('session_id', $requestedSessionId);
        } else {
            return response()->json([
                'success' => true,
                'pdfs' => [],
            ]);
        }

        $records = $query
            ->orderByDesc('updated_at')
            ->get()
            ->filter(static fn (PdfState $record) => $record->document instanceof Document);

        $pdfs = $records
            ->groupBy('document_id')
            ->map(function ($group) {
                /** @var \Illuminate\Support\Collection $group */
                $latestRecord = $group->sortByDesc(static fn (PdfState $record) => optional($record->updated_at)?->getTimestamp() ?? 0)->first();
                $document = $latestRecord?->document;
                if (!$document) {
                    return null;
                }

                return [
                    'document_id' => $document->id,
                    'pdf_name' => $document->original_name ?: basename((string) $document->path),
                    'session_id' => $latestRecord->session_id,
                    'annotation_count' => $group->count(),
                    'updated_at' => optional($latestRecord->updated_at)?->toIso8601String(),
                    'edit_url' => route('documents.edit', $document),
                    'load_url' => route('documents.loadSavedPdf', $document),
                    'delete_url' => route('documents.deleteSavedPdfOption', $document),
                ];
            })
            ->filter()
            ->sortByDesc(static fn (array $entry) => $entry['updated_at'] ?? '')
            ->values();

        return response()->json([
            'success' => true,
            'pdfs' => $pdfs,
        ]);
    }

    public function deleteSavedPdfOption(Request $request, Document $document)
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
        ]);

        $sessionId = trim((string) $validated['session_id']);
        if ($sessionId === '') {
            return response()->json([
                'success' => false,
                'message' => 'Saved PDF session is required.',
            ], 422);
        }

        $editorEmail = $this->resolveEditorEmail('');
        $deleteQuery = PdfState::query()
            ->where('document_id', $document->id)
            ->where('session_id', $sessionId);

        if ($editorEmail !== '') {
            $deleteQuery->where(function ($scopedQuery) use ($editorEmail) {
                $scopedQuery->whereNull('user_email')
                    ->orWhere('user_email', $editorEmail);
            });
        }

        $deletedCount = $deleteQuery->delete();

        return response()->json([
            'success' => true,
            'message' => $deletedCount > 0 ? 'Saved PDF deleted.' : 'No saved PDF rows matched this entry.',
            'deleted_count' => $deletedCount,
        ]);
    }

    public function loadSavedPdf(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'session_id' => 'nullable|string',
        ]);

        $requestedSessionId = is_string($validated['session_id'] ?? null)
            ? trim((string) $validated['session_id'])
            : '';

        [$resolvedSessionId, $records] = $this->resolveSavedAnnotationRecords($document, $requestedSessionId);

        if ($records->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No saved annotations were found for this document.',
            ], 404);
        }

        $annotationsPayload = $records
            ->map(static fn (PdfState $record) => is_array($record->annotation_data) ? $record->annotation_data : null)
            ->filter(static fn ($annotation) => is_array($annotation))
            ->values()
            ->all();

        $annotationsForPython = $this->prepareAnnotationsForPython($annotationsPayload);

        if (empty($annotationsPayload)) {
            return response()->json([
                'success' => false,
                'message' => 'Saved annotations are empty for this document.',
            ], 422);
        }

        $documentPdfPath = Storage::path($document->path);
        if (!file_exists($documentPdfPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Document PDF file not found.',
            ], 404);
        }

        $sourcePdfPath = $documentPdfPath;
        if ($document->original_backup_path && Storage::exists($document->original_backup_path)) {
            $originalBackupPath = Storage::path($document->original_backup_path);
            if (file_exists($originalBackupPath)) {
                $sourcePdfPath = $originalBackupPath;
            }
        }

        $editorEmail = $this->resolveEditorEmail();
        $containsPromotedExtractionSnapshot = !empty($annotationsPayload);
        $sourceTempCleanPdfPath = null;
        if ($containsPromotedExtractionSnapshot) {
            $sourceTempCleanPdfPath = $this->createCleanPdfFromExtractionSource(
                $document,
                $pythonBinary,
                $sourcePdfPath,
                $editorEmail,
                $resolvedSessionId !== '' ? $resolvedSessionId : null
            );
            if ($sourceTempCleanPdfPath) {
                $sourcePdfPath = $sourceTempCleanPdfPath;
            }
        }

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $tempPdfPath = $tempDir . '/load_saved_pdf_' . $document->id . '_' . Str::uuid() . '.pdf';
        $annotationsFile = $tempDir . '/load_saved_pdf_annotations_' . $document->id . '_' . Str::uuid() . '.json';

        if (!@copy($sourcePdfPath, $tempPdfPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to prepare the saved-PDF working copy.',
            ], 500);
        }

        $annotationsJson = json_encode($annotationsForPython, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($annotationsJson === false || @file_put_contents($annotationsFile, $annotationsJson) === false) {
            if (file_exists($tempPdfPath)) {
                @unlink($tempPdfPath);
            }
            if ($sourceTempCleanPdfPath && file_exists($sourceTempCleanPdfPath)) {
                @unlink($sourceTempCleanPdfPath);
            }
            return response()->json([
                'success' => false,
                'message' => 'Failed to prepare saved annotation payload.',
            ], 500);
        }

        $script = base_path('python/pdf-editor/apply_annotations_direct.py');
        $command = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($tempPdfPath),
            escapeshellarg($annotationsFile)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if (file_exists($annotationsFile)) {
            @unlink($annotationsFile);
        }

        if ($returnCode !== 0) {
            if (file_exists($tempPdfPath)) {
                @unlink($tempPdfPath);
            }
            if ($sourceTempCleanPdfPath && file_exists($sourceTempCleanPdfPath)) {
                @unlink($sourceTempCleanPdfPath);
            }
            \Log::error('Load saved PDF failed', [
                'document_id' => $document->id,
                'session_id' => $resolvedSessionId,
                'return_code' => $returnCode,
                'output' => implode("\n", $output),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to build the saved PDF from annotations.',
                'error' => implode("\n", $output),
            ], 500);
        }

        if (!@copy($tempPdfPath, $documentPdfPath)) {
            if (file_exists($tempPdfPath)) {
                @unlink($tempPdfPath);
            }
            if ($sourceTempCleanPdfPath && file_exists($sourceTempCleanPdfPath)) {
                @unlink($sourceTempCleanPdfPath);
            }
            return response()->json([
                'success' => false,
                'message' => 'Failed to load the saved PDF into the document.',
            ], 500);
        }

        if (file_exists($tempPdfPath)) {
            @unlink($tempPdfPath);
        }
        if ($sourceTempCleanPdfPath && file_exists($sourceTempCleanPdfPath)) {
            @unlink($sourceTempCleanPdfPath);
        }

        $records->each(function (PdfState $record) {
            $record->state = 'materialized';
            $record->save();
        });

        $document->size_bytes = @filesize($documentPdfPath) ?: $document->size_bytes;
        $document->updated_at = now();
        $document->saveQuietly();

        return response()->json([
            'success' => true,
            'message' => 'Loaded PDF from saved annotations.',
            'session_id' => $resolvedSessionId !== '' ? $resolvedSessionId : null,
            'loaded_annotations' => count($annotationsPayload),
            'file_url' => route('documents.file', $document) . '?v=' . urlencode((string) now()->timestamp),
            'edit_url' => route('documents.edit', $document),
        ]);
    }

    public function applyAnnotationsDirect(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'annotations' => 'present|array',
            'annotations.*.type' => 'required|string',
            'annotations.*.pageIndex' => 'required',
            'session_annotations' => 'nullable|array',
            'session_annotations.*.type' => 'required_with:session_annotations|string',
            'session_annotations.*.pageIndex' => 'required_with:session_annotations',
            'render_annotations' => 'nullable|array',
            'render_annotations.*.type' => 'required_with:render_annotations|string',
            'render_annotations.*.pageIndex' => 'required_with:render_annotations',
            'redraw_page_indices' => 'nullable|array',
            'redraw_page_indices.*' => 'integer|min:0',
            'deleted_promoted_source_keys' => 'nullable|array',
            'deleted_promoted_source_keys.*' => 'string',
            'use_clean_pdf' => 'nullable|boolean',
            'use_original_pdf' => 'nullable|boolean',
            'session_id' => 'nullable|string',
        ]);
        // IMPORTANT:
        // $validated['annotations'] only contains keys declared in validation rules
        // (type/pageIndex). For direct annotation stamping we need the full payload
        // (pdfX/pdfY/pdfWidth/pdfHeight/colors/etc), so read annotations from request input.
        $annotationsPayload = $request->input('annotations', []);
        if (!is_array($annotationsPayload)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid annotations payload.',
            ], 422);
        }

        $annotationsPayload = $this->normalizeAnnotationsForPersistence($document, $annotationsPayload);
        $sessionAnnotationsPayload = $request->input('session_annotations', null);
        if ($request->exists('session_annotations')) {
            if (!is_array($sessionAnnotationsPayload)) {
                return response()->json(['success' => false, 'message' => 'Invalid session annotations payload.'], 422);
            }
            $sessionAnnotationsPayload = $this->normalizeAnnotationsForPersistence($document, $sessionAnnotationsPayload);
        } else {
            $sessionAnnotationsPayload = $annotationsPayload;
        }
        $renderAnnotationsPayload = $request->input('render_annotations', []);
        if (!is_array($renderAnnotationsPayload)) {
            $renderAnnotationsPayload = [];
        }
        $renderAnnotationsPayload = $this->normalizeAnnotationsForPersistence($document, $renderAnnotationsPayload);
        $sessionId = is_string($validated['session_id'] ?? null)
            ? trim((string) $validated['session_id'])
            : '';
        $redrawPageIndices = is_array($validated['redraw_page_indices'] ?? null)
            ? array_values(array_unique(array_map('intval', $validated['redraw_page_indices'])))
            : [];
        $deletedPromotedSourceKeys = $this->mergeDeletedPromotedSourceKeys(
            $document,
            $sessionId,
            is_array($validated['deleted_promoted_source_keys'] ?? null)
                ? array_values(array_filter($validated['deleted_promoted_source_keys'], 'is_string'))
                : [],
            $annotationsPayload
        );
        if (empty($renderAnnotationsPayload) && !empty($sessionAnnotationsPayload) && !empty($redrawPageIndices)) {
            $renderAnnotationsPayload = $this->filterRenderAnnotationsForSelectiveRedraw(
                $sessionAnnotationsPayload,
                $redrawPageIndices
            );
        }
        if (empty($redrawPageIndices) && !empty($renderAnnotationsPayload)) {
            $redrawPageIndices = $this->collectAnnotationPageIndices($renderAnnotationsPayload);
        }
        if (empty($annotationsPayload) && empty($renderAnnotationsPayload) && empty($deletedPromotedSourceKeys)) {
            // AcroForm-only downloads should not enter the selective redraw path.
            // Their widget state is applied directly to the current PDF bytes after
            // fetch; rebuilding widget-only pages drops original field appearance
            // streams on later pages.
            $redrawPageIndices = [];
        }
        $redrawPageIndices = $this->mergeSelectiveRedrawPageIndices(
            $redrawPageIndices,
            $annotationsPayload,
            $renderAnnotationsPayload,
            $deletedPromotedSourceKeys
        );
        if (empty($renderAnnotationsPayload) && !empty($redrawPageIndices)) {
            $renderAnnotationsPayload = $this->filterRenderAnnotationsForSelectiveRedraw(
                !empty($sessionAnnotationsPayload) ? $sessionAnnotationsPayload : $annotationsPayload,
                $redrawPageIndices
            );
        }

        $annotationsPayload = array_map(function ($annotation) use ($document) {
            if (!is_array($annotation)) {
                return $annotation;
            }
            $annotation['__documentId'] = $document->id;
            return $annotation;
        }, $annotationsPayload);
        $renderAnnotationsPayload = array_map(function ($annotation) use ($document) {
            if (!is_array($annotation)) {
                return $annotation;
            }
            $annotation['__documentId'] = $document->id;
            return $annotation;
        }, $renderAnnotationsPayload);
        $persistableAnnotationsPayload = array_map(static function ($annotation) {
            if (!is_array($annotation)) {
                return [];
            }
            unset($annotation['__documentId']);
            return $annotation;
        }, $annotationsPayload);
        $pdfPath = Storage::path($document->path);
        $useCleanPdf = $request->boolean('use_clean_pdf');
        $useOriginalPdf = $request->boolean('use_original_pdf');
        $workingPdfPath = $pdfPath;
        $tempWorkingPdfPath = null;
        $editorEmail = $this->resolveEditorEmail();

        if ($useOriginalPdf) {
            if (!$document->original_backup_path || !Storage::exists($document->original_backup_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Original PDF source not found.',
                ], 404);
            }

            $originalPath = Storage::path($document->original_backup_path);
            if (!file_exists($originalPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Original PDF source not found.',
                ], 404);
            }

            $tempWorkingPdfPath = Storage::path('temp/apply_annotations_original_' . $document->id . '_' . Str::uuid() . '.pdf');
            $tempWorkingDir = dirname($tempWorkingPdfPath);
            if (!is_dir($tempWorkingDir)) {
                @mkdir($tempWorkingDir, 0775, true);
            }
            if (!@copy($originalPath, $tempWorkingPdfPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to prepare original PDF working copy.',
                ], 500);
            }
            $workingPdfPath = $tempWorkingPdfPath;
        } elseif ($useCleanPdf) {
            $cleanPath = $this->ensureCleanPdfPath(
                $document,
                $pythonBinary,
                $editorEmail,
                $sessionId !== '' ? $sessionId : null
            );
            if (!$cleanPath || !file_exists($cleanPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Clean PDF source not found.',
                ], 404);
            }

            $tempWorkingPdfPath = Storage::path('temp/apply_annotations_' . $document->id . '_' . Str::uuid() . '.pdf');
            $tempWorkingDir = dirname($tempWorkingPdfPath);
            if (!is_dir($tempWorkingDir)) {
                @mkdir($tempWorkingDir, 0775, true);
            }
            if (!@copy($cleanPath, $tempWorkingPdfPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to prepare clean PDF working copy.',
                ], 500);
            }
            $workingPdfPath = $tempWorkingPdfPath;
        }

        if (!file_exists($pdfPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Document file not found.',
            ], 404);
        }

        $backupPath = Storage::path('documents/backup_' . pathinfo($document->path, PATHINFO_FILENAME) . '.pdf');
        if (file_exists($pdfPath)) {
            @copy($pdfPath, $backupPath);
        }

        $tempJsonDir = storage_path('app/temp');
        if (!is_dir($tempJsonDir)) {
            @mkdir($tempJsonDir, 0775, true);
        }
        $makeTempFile = function (string $prefix) use ($tempJsonDir, $document) {
            $candidates = [$tempJsonDir, sys_get_temp_dir()];
            foreach ($candidates as $dir) {
                if (!$dir || !is_dir($dir) || !is_writable($dir)) {
                    continue;
                }
                $path = rtrim($dir, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR
                    . $prefix
                    . $document->id
                    . '_'
                    . uniqid('', true)
                    . '.json';
                if (@file_put_contents($path, '') !== false) {
                    return $path;
                }
            }
            throw new \RuntimeException('Failed to allocate temporary JSON file path.');
        };

        try {
            $annotationsFile = $makeTempFile('annotations_');
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to allocate annotations temp file.',
            ], 500);
        }

        $annotationsJson = json_encode($this->prepareAnnotationsForPython($persistableAnnotationsPayload), JSON_INVALID_UTF8_SUBSTITUTE);
        if ($annotationsJson === false || @file_put_contents($annotationsFile, $annotationsJson) === false) {
            if (isset($annotationsFile) && file_exists($annotationsFile)) {
                @unlink($annotationsFile);
            }
            return response()->json([
                'success' => false,
                'message' => 'Failed to write annotations temp file.',
            ], 500);
        }

        $script = base_path('python/pdf-editor/apply_annotations_direct.py');
        $command = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($workingPdfPath),
            escapeshellarg($annotationsFile)
        );
        $output = [];
        $returnCode = 0;
        $usedSelectiveRedraw = false;

        if (!empty($redrawPageIndices)) {
            $selectiveResult = $this->runSelectiveAnnotationPageRedraw(
                $document,
                $pythonBinary,
                $workingPdfPath,
                $pdfPath,
                $renderAnnotationsPayload,
                $redrawPageIndices,
                $deletedPromotedSourceKeys,
                $this->resolveEditorEmail(),
                $sessionId !== '' ? $sessionId : null
            );

            if (!($selectiveResult['success'] ?? false)) {
                $message = (string) ($selectiveResult['message'] ?? '');
                $errorText = (string) ($selectiveResult['error'] ?? '');
                $shouldFallbackToDirect = str_contains($message, 'Selective redraw is blocked for AcroForm widget page(s):')
                    || str_contains($errorText, 'Selective redraw is blocked for AcroForm widget page(s):');
                if (!$shouldFallbackToDirect) {
                    if (file_exists($annotationsFile)) {
                        @unlink($annotationsFile);
                    }
                    if (file_exists($backupPath)) {
                        @copy($backupPath, $pdfPath);
                    }
                    if ($tempWorkingPdfPath && file_exists($tempWorkingPdfPath)) {
                        @unlink($tempWorkingPdfPath);
                    }
                    return response()->json([
                        'success' => false,
                        'message' => $selectiveResult['message'] ?? 'Failed to redraw changed pages.',
                        'error' => $selectiveResult['error'] ?? null,
                    ], 500);
                }
                exec($command, $output, $returnCode);
            } else {
                $usedSelectiveRedraw = true;
            }
        } else {
            exec($command, $output, $returnCode);
        }

        if (file_exists($annotationsFile)) {
            @unlink($annotationsFile);
        }

        if (!$usedSelectiveRedraw && $returnCode !== 0) {
            if (file_exists($backupPath)) {
                @copy($backupPath, $pdfPath);
            }
            if ($tempWorkingPdfPath && file_exists($tempWorkingPdfPath)) {
                @unlink($tempWorkingPdfPath);
            }
            \Log::error('Direct annotation apply failed', [
                'document_id' => $document->id,
                'return_code' => $returnCode,
                'output' => implode("\n", $output),
                'use_clean_pdf' => $useCleanPdf,
                'use_original_pdf' => $useOriginalPdf,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to apply annotations directly.',
                'error' => implode("\n", $output),
            ], 500);
        }

        if ($useCleanPdf || $useOriginalPdf) {
            if (!@copy($workingPdfPath, $pdfPath)) {
                if ($tempWorkingPdfPath && file_exists($tempWorkingPdfPath)) {
                    @unlink($tempWorkingPdfPath);
                }
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to promote working PDF save back to the document file.',
                ], 500);
            }
            if ($tempWorkingPdfPath && file_exists($tempWorkingPdfPath)) {
                @unlink($tempWorkingPdfPath);
            }
        }

        $size = @filesize($pdfPath) ?: $document->size_bytes;
        $document->size_bytes = $size;
        $document->saveQuietly();

        \Log::info('Direct annotation apply SUCCESS', [
            'document_id' => $document->id,
            'annotation_count' => count($annotationsPayload),
            'annotation_types' => array_map(fn($a) => $a['type'] ?? 'unknown', $annotationsPayload),
            'first_annotation' => $annotationsPayload[0] ?? null,
            'new_size' => $size,
            'use_clean_pdf' => $useCleanPdf,
            'use_original_pdf' => $useOriginalPdf,
            'used_selective_redraw' => $usedSelectiveRedraw,
            'redraw_pages' => $redrawPageIndices,
        ]);

        if ($sessionId !== '') {
            foreach ($persistableAnnotationsPayload as $annotation) {
                if (!is_array($annotation)) {
                    continue;
                }
                $annotationId = is_string($annotation['id'] ?? null)
                    ? trim((string) $annotation['id'])
                    : '';
                if ($annotationId === '') {
                    continue;
                }

                $existingRecord = PdfState::query()
                    ->where('document_id', $document->id)
                    ->where('session_id', $sessionId)
                    ->whereRaw("JSON_EXTRACT(annotation_data, '$.id') = ?", [$annotationId])
                    ->first();

                if ($existingRecord) {
                    $existingRecord->update([
                        'annotation_data' => $annotation,
                        'page_number' => $annotation['pageIndex'] ?? null,
                        'state' => 'saved',
                    ]);
                    continue;
                }

                PdfState::create([
                    'document_id' => $document->id,
                    'user_email' => null,
                    'session_id' => $sessionId,
                    'page_number' => $annotation['pageIndex'] ?? null,
                    'annotation_data' => $annotation,
                    'state' => 'saved',
                ]);
            }

            // Do not purge rows absent from this payload. Direct save uses a
            // material-delta annotation payload, not a complete layer snapshot.
        }

        return response()->json([
            'success' => true,
            'message' => 'Annotations applied directly to PDF.',
        ]);
    }

    public function stampPdfStatePreview(Request $request)
    {
        $validated = $request->validate([
            'annotation' => 'nullable|array',
            'annotations' => 'nullable|array',
            'document_id' => 'nullable|integer|exists:documents,id',
            'pdf_state_source' => 'nullable|string|in:saved,extracted',
            'page_width' => 'nullable|numeric|min:1',
            'page_height' => 'nullable|numeric|min:1',
            'reuse_url' => 'nullable|boolean',
            'use_base' => 'nullable|boolean',
        ]);

        $annotationsPayload = $request->input('annotations', null);
        $singleAnnotationPayload = $request->input('annotation', null);
        $documentIdFilter = isset($validated['document_id']) ? (int) $validated['document_id'] : 0;
        $pdfStateSource = (string) ($validated['pdf_state_source'] ?? 'saved');
        $useBase = $request->has('use_base')
            ? (bool) ($validated['use_base'] ?? false)
            : ($documentIdFilter > 0);
        $pdfStateIdInput = $request->input('pdf_state_id', null);
        $pdfStateIds = [];
        if (is_array($pdfStateIdInput)) {
            foreach ($pdfStateIdInput as $value) {
                if (is_numeric($value)) {
                    $pdfStateIds[] = (int) $value;
                }
            }
        } elseif (is_numeric($pdfStateIdInput)) {
            $pdfStateIds[] = (int) $pdfStateIdInput;
        }
        $pdfStateIds = array_values(array_unique(array_filter($pdfStateIds, static fn ($value) => $value > 0)));

        $annotations = [];
        $source = null;
        $usingDocumentStateSource = false;

        if (is_array($annotationsPayload)) {
            $annotations = array_values(array_filter($annotationsPayload, static fn ($annotation) => is_array($annotation)));
            $source = 'annotations';
        } elseif (is_array($singleAnnotationPayload)) {
            $annotations = [$singleAnnotationPayload];
            $source = 'annotation';
        } elseif (!empty($pdfStateIds)) {
            $records = PdfState::query()
                ->whereIn('id', $pdfStateIds)
                ->get()
                ->keyBy(static fn (PdfState $record) => (int) $record->id);

            $missingIds = array_values(array_filter(
                $pdfStateIds,
                static fn ($id) => !$records->has((int) $id)
            ));
            if (!empty($missingIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'One or more requested pdf_state rows were not found.',
                    'missing_ids' => $missingIds,
                ], 422);
            }

            foreach ($pdfStateIds as $pdfStateId) {
                $record = $records->get((int) $pdfStateId);
                $annotationData = $record && is_array($record->annotation_data) ? $record->annotation_data : null;
                if (!$annotationData) {
                    return response()->json([
                        'success' => false,
                        'message' => "pdf_state row {$pdfStateId} does not contain an annotation payload.",
                    ], 422);
                }
                $annotationData['documentId'] = (int) ($record->document_id ?? 0);
                $annotationData['__documentId'] = (int) ($record->document_id ?? 0);
                $annotations[] = $annotationData;
            }
            $source = count($pdfStateIds) === 1 ? 'pdf_state' : 'pdf_state_array';
        } elseif ($documentIdFilter > 0) {
            $records = PdfState::query()
                ->where('document_id', $documentIdFilter)
                ->where('state', $pdfStateSource)
                ->orderBy('id')
                ->get();

            if ($records->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => "No {$pdfStateSource} pdf_state annotations were found for the requested document.",
                    'document_id' => $documentIdFilter,
                    'pdf_state_source' => $pdfStateSource,
                ], 422);
            }

            foreach ($records as $record) {
                $annotationData = is_array($record->annotation_data) ? $record->annotation_data : null;
                if (!$annotationData) {
                    continue;
                }
                $annotationData['documentId'] = (int) ($record->document_id ?? 0);
                $annotationData['__documentId'] = (int) ($record->document_id ?? 0);
                $annotations[] = $annotationData;
            }

            if (empty($annotations)) {
                return response()->json([
                    'success' => false,
                    'message' => "No valid {$pdfStateSource} pdf_state annotation payloads were found for the requested document.",
                    'document_id' => $documentIdFilter,
                    'pdf_state_source' => $pdfStateSource,
                ], 422);
            }

            $source = 'document_pdf_state_' . $pdfStateSource;
            $usingDocumentStateSource = true;
        } else {
            $rawPayload = $request->all();
            if (is_array($rawPayload) && isset($rawPayload['type'])) {
                $annotations = [$rawPayload];
                $source = 'raw_annotation';
            } elseif (array_is_list($rawPayload) && !empty($rawPayload) && is_array($rawPayload[0] ?? null)) {
                $annotations = array_values(array_filter($rawPayload, static fn ($annotation) => is_array($annotation)));
                $source = 'raw_annotations';
            }
        }

        if (empty($annotations)) {
            return response()->json([
                'success' => false,
                'message' => 'Provide document_id, pdf_state_id, annotation, or annotations.',
            ], 422);
        }

        if ($usingDocumentStateSource) {
            $useBase = true;
        }

        $normalizedAnnotations = [];
        foreach ($annotations as $index => $annotation) {
            if (!is_array($annotation)) {
                continue;
            }
            $normalizedAnnotation = $annotation;
            if ($useBase) {
                $normalizedAnnotation['pageIndex'] = is_numeric($normalizedAnnotation['pageIndex'] ?? null)
                    ? max(0, (int) $normalizedAnnotation['pageIndex'])
                    : 0;
            } else {
                $normalizedAnnotation['pageIndex'] = 0;
            }
            $annotationId = trim((string) ($normalizedAnnotation['id'] ?? ''));
            if ($annotationId === '') {
                $normalizedAnnotation['id'] = 'blank_preview_' . ($index + 1);
            }
            $normalizedAnnotations[] = $normalizedAnnotation;
        }

        if (empty($normalizedAnnotations)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid annotation payloads were provided.',
            ], 422);
        }

        $annotationDocumentIds = [];
        foreach ($normalizedAnnotations as $annotation) {
            if (!is_array($annotation)) {
                continue;
            }
            $documentIdValue = $annotation['documentId'] ?? $annotation['__documentId'] ?? null;
            if (is_numeric($documentIdValue) && (int) $documentIdValue > 0) {
                $annotationDocumentIds[] = (int) $documentIdValue;
            }
        }
        $annotationDocumentIds = array_values(array_unique($annotationDocumentIds));

        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
        $scriptPath = base_path('python/pdf-editor/stamp_annotations_on_blank_pdf.py');
        if (!is_file($scriptPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Blank preview stamping script not found.',
            ], 500);
        }

        $baseDocumentId = isset($validated['document_id']) ? (int) $validated['document_id'] : 0;
        $resolvedBasePdfPath = null;
        $resolvedSourcePdfPath = null;
        if ($useBase) {
            if ($baseDocumentId <= 0) {
                if (count($annotationDocumentIds) === 1) {
                    $baseDocumentId = $annotationDocumentIds[0];
                } elseif (count($annotationDocumentIds) > 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'use_base requires all annotations to belong to one document, or an explicit document_id.',
                        'document_ids' => $annotationDocumentIds,
                    ], 422);
                }
            }

            if ($baseDocumentId <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'use_base requires a document_id or pdf_state rows tied to one document.',
                ], 422);
            }

            $baseDocument = Document::query()->find($baseDocumentId);
            if (!$baseDocument) {
                return response()->json([
                    'success' => false,
                    'message' => 'Requested base document was not found.',
                    'document_id' => $baseDocumentId,
                ], 422);
            }

            $resolvedBasePdfPath = $this->ensureCleanPdfPath($baseDocument, $pythonBinary);
            if (!$resolvedBasePdfPath || !is_file($resolvedBasePdfPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to resolve the clean redacted base PDF for the requested document.',
                    'document_id' => $baseDocumentId,
                ], 500);
            }

            $resolvedSourcePdfPath = Storage::path($baseDocument->path);
            if ($baseDocument->original_backup_path && Storage::exists($baseDocument->original_backup_path)) {
                $originalBackupPath = Storage::path($baseDocument->original_backup_path);
                if (is_file($originalBackupPath)) {
                    $resolvedSourcePdfPath = $originalBackupPath;
                }
            }
            if (!$resolvedSourcePdfPath || !is_file($resolvedSourcePdfPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to resolve the original source PDF for the requested document.',
                    'document_id' => $baseDocumentId,
                ], 500);
            }
        }

        Storage::disk('public')->makeDirectory('debug/pdf-state-stamps');
        $reuseUrl = (bool) ($validated['reuse_url'] ?? false);
        $previewModeSuffix = $useBase
            ? '-base-' . ($baseDocumentId > 0 ? $baseDocumentId : 'doc')
            : '-blank';
        $stableArrayScope = count($annotationDocumentIds) === 1
            ? 'doc-' . $annotationDocumentIds[0]
            : 'shared';
        if ($reuseUrl) {
            if ($usingDocumentStateSource && $documentIdFilter > 0) {
                $stableFilename = 'pdf-state-document-' . $documentIdFilter . '-' . $pdfStateSource . $previewModeSuffix . '.pdf';
            } elseif (!empty($pdfStateIds)) {
                if (count($pdfStateIds) === 1) {
                    $stableFilename = 'pdf-state-' . $pdfStateIds[0] . $previewModeSuffix . '.pdf';
                } else {
                    $stableFilename = 'pdf-state-array-' . $stableArrayScope . $previewModeSuffix . '.pdf';
                }
            } else {
                $stableFilename = 'payload-' . substr(
                    sha1(
                        json_encode($normalizedAnnotations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        . $previewModeSuffix
                    ),
                    0,
                    20
                ) . '.pdf';
            }
            $relativeOutputPath = 'debug/pdf-state-stamps/' . $stableFilename;
        } else {
            $relativeOutputPath = 'debug/pdf-state-stamps/' . Str::uuid() . '.pdf';
        }
        $absoluteOutputPath = Storage::disk('public')->path($relativeOutputPath);

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $annotationsFile = tempnam($tempDir, 'blank_pdf_state_preview_');
        if ($annotationsFile === false) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to allocate temporary annotation file.',
            ], 500);
        }

        file_put_contents(
            $annotationsFile,
            json_encode($normalizedAnnotations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $command = sprintf(
            '%s %s %s %s%s%s%s%s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($scriptPath),
            escapeshellarg($annotationsFile),
            escapeshellarg($absoluteOutputPath),
            isset($validated['page_width']) ? ' ' . escapeshellarg((string) $validated['page_width']) : '',
            isset($validated['page_height']) ? ' ' . escapeshellarg((string) $validated['page_height']) : '',
            $resolvedBasePdfPath ? ' --base-pdf ' . escapeshellarg($resolvedBasePdfPath) : '',
            $resolvedSourcePdfPath ? ' --source-pdf ' . escapeshellarg($resolvedSourcePdfPath) : ''
        );

        $output = [];
        $returnCode = 1;
        try {
            exec($command, $output, $returnCode);
        } finally {
            @unlink($annotationsFile);
        }

        if ($returnCode !== 0 || !is_file($absoluteOutputPath)) {
            if (is_file($absoluteOutputPath)) {
                @unlink($absoluteOutputPath);
            }
            return response()->json([
                'success' => false,
                'message' => 'Failed to stamp annotation preview PDF.',
                'error' => implode("\n", $output),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'source' => $source,
            'annotation_count' => count($normalizedAnnotations),
            'reuse_url' => $reuseUrl,
            'use_base' => $useBase,
            'pdf_state_source' => $usingDocumentStateSource ? $pdfStateSource : null,
            'document_id' => $baseDocumentId > 0 ? $baseDocumentId : null,
            'file_url' => url(Storage::url($relativeOutputPath)),
            'relative_path' => $relativeOutputPath,
            'debug_output' => implode("\n", $output),
        ]);
    }

    public function markAnnotationsSaved(Request $request, Document $document)
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'annotation_ids' => 'nullable|array',
            'annotation_ids.*' => 'nullable|string',
        ]);

        $sessionId = $validated['session_id'];
        $annotationIds = array_values(array_filter(array_map(
            static fn ($value) => is_string($value) ? trim($value) : '',
            $validated['annotation_ids'] ?? []
        )));

        if (!empty($annotationIds)) {
            foreach ($annotationIds as $annotationId) {
                PdfState::where('document_id', $document->id)
                    ->where('session_id', $sessionId)
                    ->whereRaw("JSON_EXTRACT(annotation_data, '$.id') = ?", [$annotationId])
                    ->update(['state' => 'saved']);
            }
        } else {
            PdfState::where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->update(['state' => 'saved']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Annotations marked as saved',
        ]);
    }

    public function deleteAnnotations(Request $request, Document $document)
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'annotation_ids' => 'present|array',
            'annotation_ids.*' => 'string',
            'deleted_promoted_source_keys' => 'nullable|array',
            'deleted_promoted_source_keys.*' => 'string',
        ]);

        $sessionId = trim((string) $validated['session_id']);
        $annotationIds = array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? trim($v) : '',
            $validated['annotation_ids']
        )));
        $deletedPromotedSourceKeys = array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? trim($v) : '',
            is_array($validated['deleted_promoted_source_keys'] ?? null)
                ? $validated['deleted_promoted_source_keys']
                : []
        )));

        foreach ($annotationIds as $annotationId) {
            $record = PdfState::where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.id')) = ?", [$annotationId])
                ->first();

            if (!$record) {
                continue;
            }

            $annotationData = is_array($record->annotation_data) ? $record->annotation_data : [];
            if (!empty($annotationData['promotedFromExtraction']) && !empty($annotationData['promotedSourceKey'])) {
                $annotationData['_explicitPromotedDelete'] = true;
            }

            $record->annotation_data = $annotationData;
            $record->state = 'deleted';
            $record->save();
        }

        foreach ($deletedPromotedSourceKeys as $sourceKey) {
            $existingRecord = PdfState::query()
                ->where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.promotedSourceKey')) = ?", [$sourceKey])
                ->orderByDesc('updated_at')
                ->first();

            if ($existingRecord) {
                $annotationData = is_array($existingRecord->annotation_data) ? $existingRecord->annotation_data : [];
                $annotationData['promotedFromExtraction'] = true;
                $annotationData['promotedSourceKey'] = $sourceKey;
                $annotationData['_explicitPromotedDelete'] = true;
                $existingRecord->annotation_data = $annotationData;
                $existingRecord->state = 'deleted';
                $existingRecord->save();
                continue;
            }

            PdfState::create([
                'document_id' => $document->id,
                'user_email' => null,
                'session_id' => $sessionId,
                'page_number' => $this->parsePromotedSourceKeyPageIndex($sourceKey),
                'annotation_data' => [
                    'id' => 'deleted_promoted_' . Str::uuid(),
                    'type' => 'text',
                    'promotedFromExtraction' => true,
                    'promotedSourceKey' => $sourceKey,
                    '_explicitPromotedDelete' => true,
                ],
                'state' => 'deleted',
            ]);
        }

        return response()->json([
            'success' => true,
            'deleted' => count($annotationIds),
            'deleted_promoted_source_keys' => $deletedPromotedSourceKeys,
        ]);
    }

    /**
     * Upsert all current annotations to PdfState DB without stamping the PDF.
     * Called by the "Save" button — shapes stay editable in the editor.
     */
    public function saveAnnotationState(Request $request, Document $document)
    {
        $validated = $request->validate([
            'annotations' => 'nullable|array',
            'annotations.*.type' => 'required_with:annotations|string',
            'annotations.*.pageIndex' => 'required_with:annotations',
            'session_annotations' => 'nullable|array',
            'session_annotations.*.type' => 'required_with:session_annotations|string',
            'session_annotations.*.pageIndex' => 'required_with:session_annotations',
            'acro_form_entries' => 'nullable|array',
            'session_id' => 'nullable|string',
        ]);

        $annotationsPayload = $request->input('annotations', []);
        if (!is_array($annotationsPayload)) {
            return response()->json(['success' => false, 'message' => 'Invalid annotations payload.'], 422);
        }

        $annotationsPayload = $this->normalizeAnnotationsForPersistence($document, $annotationsPayload);
        $sessionAnnotationsPayload = $request->input('session_annotations', null);
        if ($request->exists('session_annotations')) {
            if (!is_array($sessionAnnotationsPayload)) {
                return response()->json(['success' => false, 'message' => 'Invalid session annotations payload.'], 422);
            }
            $sessionAnnotationsPayload = $this->normalizeAnnotationsForPersistence($document, $sessionAnnotationsPayload);
        } else {
            $sessionAnnotationsPayload = $annotationsPayload;
        }
        $acroFormEntries = $this->normalizeAcroFormEntriesForPersistence(
            is_array($validated['acro_form_entries'] ?? null) ? $validated['acro_form_entries'] : []
        );

        $sessionId = is_string($validated['session_id'] ?? null)
            ? trim((string) $validated['session_id'])
            : '';

        if ($sessionId !== '') {
            $this->upsertPdfStateSessionSnapshot(
                $document,
                $sessionId,
                $sessionAnnotationsPayload,
                'saved'
            );

            $this->upsertPdfAcroFormSessionState(
                $document,
                $sessionId,
                $acroFormEntries,
                Auth::id(),
                'saved'
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Annotation state saved.',
            'session_id' => $sessionId !== '' ? $sessionId : null,
        ]);
    }

    /**
     * Stamp annotations onto a temporary copy of the PDF and serve it as a
     * file download. Never overwrites document.path. Also saves annotation state to DB.
     */
    public function downloadAnnotatedPdf(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'annotations' => 'present|array',
            'annotations.*.type' => 'required|string',
            'annotations.*.pageIndex' => 'required',
            'acro_form_entries' => 'nullable|array',
            'session_annotations' => 'nullable|array',
            'session_annotations.*.type' => 'required_with:session_annotations|string',
            'session_annotations.*.pageIndex' => 'required_with:session_annotations',
            'render_annotations' => 'nullable|array',
            'render_annotations.*.type' => 'required_with:render_annotations|string',
            'render_annotations.*.pageIndex' => 'required_with:render_annotations',
            'redraw_page_indices' => 'nullable|array',
            'redraw_page_indices.*' => 'integer|min:0',
            'deleted_promoted_source_keys' => 'nullable|array',
            'deleted_promoted_source_keys.*' => 'string',
            'use_clean_pdf' => 'nullable|boolean',
            'use_original_pdf' => 'nullable|boolean',
            'use_exact_download_path' => 'nullable|boolean',
            'session_id' => 'nullable|string',
        ]);

        $annotationsPayload = $request->input('annotations', []);
        if (!is_array($annotationsPayload)) {
            return response()->json(['success' => false, 'message' => 'Invalid annotations payload.'], 422);
        }

        $annotationsPayload = $this->normalizeAnnotationsForPersistence($document, $annotationsPayload);
        $sessionAnnotationsPayload = $request->input('session_annotations', null);
        if ($request->exists('session_annotations')) {
            if (!is_array($sessionAnnotationsPayload)) {
                return response()->json(['success' => false, 'message' => 'Invalid session annotations payload.'], 422);
            }
            $sessionAnnotationsPayload = $this->normalizeAnnotationsForPersistence($document, $sessionAnnotationsPayload);
        } else {
            $sessionAnnotationsPayload = $annotationsPayload;
        }
        $renderAnnotationsPayload = $request->input('render_annotations', []);
        if (!is_array($renderAnnotationsPayload)) {
            $renderAnnotationsPayload = [];
        }
        $renderAnnotationsPayload = $this->normalizeAnnotationsForPersistence($document, $renderAnnotationsPayload);
        $acroFormEntries = $this->normalizeAcroFormEntriesForPersistence(
            is_array($validated['acro_form_entries'] ?? null) ? $validated['acro_form_entries'] : []
        );
        $sessionId = is_string($validated['session_id'] ?? null)
            ? trim((string) $validated['session_id'])
            : '';
        $redrawPageIndices = is_array($validated['redraw_page_indices'] ?? null)
            ? array_values(array_unique(array_map('intval', $validated['redraw_page_indices'])))
            : [];
        $deletedPromotedSourceKeys = $this->mergeDeletedPromotedSourceKeys(
            $document,
            $sessionId,
            is_array($validated['deleted_promoted_source_keys'] ?? null)
                ? array_values(array_filter($validated['deleted_promoted_source_keys'], 'is_string'))
                : [],
            $annotationsPayload
        );
        if (empty($renderAnnotationsPayload) && !empty($sessionAnnotationsPayload) && !empty($redrawPageIndices)) {
            $renderAnnotationsPayload = $this->filterRenderAnnotationsForSelectiveRedraw(
                $sessionAnnotationsPayload,
                $redrawPageIndices
            );
        }
        if (empty($redrawPageIndices) && !empty($renderAnnotationsPayload)) {
            $redrawPageIndices = $this->collectAnnotationPageIndices($renderAnnotationsPayload);
        }
        $redrawPageIndices = $this->mergeSelectiveRedrawPageIndices(
            $redrawPageIndices,
            $annotationsPayload,
            $renderAnnotationsPayload,
            $deletedPromotedSourceKeys
        );
        if (empty($renderAnnotationsPayload) && !empty($redrawPageIndices)) {
            $renderAnnotationsPayload = $this->filterRenderAnnotationsForSelectiveRedraw(
                !empty($sessionAnnotationsPayload) ? $sessionAnnotationsPayload : $annotationsPayload,
                $redrawPageIndices
            );
        }

        $pdfPath = Storage::path($document->path);
        $useCleanPdf = $request->boolean('use_clean_pdf');
        $useOriginalPdf = $request->boolean('use_original_pdf');
        $useExactDownloadPath = $request->boolean('use_exact_download_path');
        $editorEmail = $this->resolveEditorEmail();
        $originalSourcePdfPath = $pdfPath;
        if ($document->original_backup_path && Storage::exists($document->original_backup_path)) {
            $originalBackupPath = Storage::path($document->original_backup_path);
            if (file_exists($originalBackupPath)) {
                $originalSourcePdfPath = $originalBackupPath;
            }
        }

        // Select the redraw working source separately from the preserve source
        // used for untouched pages. Clean PDFs are valid redraw bases but cannot
        // be used to preserve untouched pages because their text layer is stripped.
        $sourcePdfPath = $pdfPath;
        $preservePdfPath = $pdfPath;
        $useExactCleanRebuild = $useExactDownloadPath && !empty($sessionAnnotationsPayload);
        if ($useExactCleanRebuild) {
            $cleanPath = $this->ensureCleanPdfPath(
                $document,
                $pythonBinary,
                $editorEmail,
                $sessionId !== '' ? $sessionId : null
            );
            if (!$cleanPath || !file_exists($cleanPath)) {
                return response()->json(['success' => false, 'message' => 'Clean PDF source not found.'], 404);
            }
            $sourcePdfPath = $cleanPath;
            $preservePdfPath = $originalSourcePdfPath;
        } elseif ($useOriginalPdf && $document->original_backup_path && Storage::exists($document->original_backup_path)) {
            $originalPath = Storage::path($document->original_backup_path);
            if (file_exists($originalPath)) {
                $sourcePdfPath = $originalPath;
                $preservePdfPath = $originalPath;
            }
        } elseif ($useCleanPdf) {
            $cleanPath = $this->ensureCleanPdfPath(
                $document,
                $pythonBinary,
                $editorEmail,
                $sessionId !== '' ? $sessionId : null
            );
            if (!$cleanPath || !file_exists($cleanPath)) {
                return response()->json(['success' => false, 'message' => 'Clean PDF source not found.'], 404);
            }
            $sourcePdfPath = $cleanPath;
        }

        if (!file_exists($sourcePdfPath)) {
            return response()->json(['success' => false, 'message' => 'Source PDF not found.'], 404);
        }

        if ($useExactCleanRebuild) {
            // Temporary exact-download path: rebuild from the clean/redacted base
            // using the full session annotation set plus replay from the original
            // source PDF for unchanged promoted extraction blocks.
            $redrawPageIndices = [];
            $renderAnnotationsPayload = [];
        }

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        // Copy source to a temp file — we never touch document.path.
        $tempPdfPath = $tempDir . '/download_annotated_' . $document->id . '_' . Str::uuid() . '.pdf';
        if (!@copy($sourcePdfPath, $tempPdfPath)) {
            return response()->json(['success' => false, 'message' => 'Failed to prepare PDF working copy.'], 500);
        }

        // Write annotations payload to a temp JSON file for the Python script.
        $annotationsFile = $tempDir . '/download_ann_' . $document->id . '_' . uniqid('', true) . '.json';
        $pythonAnnotationsPayload = $useExactCleanRebuild ? $sessionAnnotationsPayload : $annotationsPayload;
        $preparedAnnotationsForPython = $this->prepareAnnotationsForPython($pythonAnnotationsPayload);
        if ($useExactCleanRebuild) {
            $preparedAnnotationsForPython = array_map(static function ($annotation) use ($preservePdfPath) {
                if (!is_array($annotation)) {
                    return $annotation;
                }
                $annotation['__sourcePdfPath'] = $preservePdfPath;
                return $annotation;
            }, $preparedAnnotationsForPython);
        }
        $annotationsJson = json_encode($preparedAnnotationsForPython, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($annotationsJson === false || @file_put_contents($annotationsFile, $annotationsJson) === false) {
            @unlink($tempPdfPath);
            return response()->json(['success' => false, 'message' => 'Failed to prepare annotations payload.'], 500);
        }

        $script = base_path(
            $useExactDownloadPath
                ? 'python/pdf-editor/apply_annotations_direct_new.py'
                : 'python/pdf-editor/apply_annotations_direct.py'
        );
        $command = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($tempPdfPath),
            escapeshellarg($annotationsFile)
        );

        $output = [];
        $returnCode = 0;
        $usedSelectiveRedraw = false;

        if (!empty($redrawPageIndices)) {
            $selectiveResult = $this->runSelectiveAnnotationPageRedraw(
                $document,
                $pythonBinary,
                $tempPdfPath,
                $preservePdfPath,
                $renderAnnotationsPayload,
                $redrawPageIndices,
                $deletedPromotedSourceKeys,
                $this->resolveEditorEmail(),
                $sessionId !== '' ? $sessionId : null
            );

            if (!($selectiveResult['success'] ?? false)) {
                $message = (string) ($selectiveResult['message'] ?? '');
                $errorText = (string) ($selectiveResult['error'] ?? '');
                $shouldFallbackToDirect = str_contains($message, 'Selective redraw is blocked for AcroForm widget page(s):')
                    || str_contains($errorText, 'Selective redraw is blocked for AcroForm widget page(s):');
                if (!$shouldFallbackToDirect) {
                    if (file_exists($annotationsFile)) {
                        @unlink($annotationsFile);
                    }
                    @unlink($tempPdfPath);
                    return response()->json([
                        'success' => false,
                        'message' => $selectiveResult['message'] ?? 'Failed to generate selectively redrawn PDF.',
                        'error' => $selectiveResult['error'] ?? null,
                    ], 500);
                }
                exec($command, $output, $returnCode);
            } else {
                $usedSelectiveRedraw = true;
            }
        } else {
            exec($command, $output, $returnCode);
        }

        if (file_exists($annotationsFile)) {
            @unlink($annotationsFile);
        }

        if (!$usedSelectiveRedraw && $returnCode !== 0) {
            @unlink($tempPdfPath);
            \Log::error('Download annotated PDF failed', [
                'document_id' => $document->id,
                'return_code' => $returnCode,
                'output' => implode("\n", $output),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate annotated PDF.',
                'error' => implode("\n", $output),
            ], 500);
        }

        // Save annotation state to DB (same as saveAnnotationState).
        if ($sessionId !== '') {
            $this->upsertPdfStateSessionSnapshot(
                $document,
                $sessionId,
                $sessionAnnotationsPayload,
                'saved'
            );

            $this->upsertPdfAcroFormSessionState(
                $document,
                $sessionId,
                $acroFormEntries,
                Auth::id(),
                'saved'
            );
        }

        if (!empty($acroFormEntries)) {
            $acroFormApplyResult = $this->applyAcroFormEntriesToPdf($tempPdfPath, $acroFormEntries, $pythonBinary);
            if (!($acroFormApplyResult['success'] ?? false)) {
                @unlink($tempPdfPath);
                return response()->json([
                    'success' => false,
                    'message' => $acroFormApplyResult['message'] ?? 'Failed to apply AcroForm values.',
                    'error' => $acroFormApplyResult['error'] ?? null,
                ], 500);
            }
            $appliedPdfPath = (string) ($acroFormApplyResult['output_pdf_path'] ?? '');
            if ($appliedPdfPath !== '' && $appliedPdfPath !== $tempPdfPath && file_exists($appliedPdfPath)) {
                @unlink($tempPdfPath);
                $tempPdfPath = $appliedPdfPath;
            }
        }

        $downloadName = pathinfo($document->original_name ?? basename((string) $document->path), PATHINFO_FILENAME) . '_annotated.pdf';

        return response()->download($tempPdfPath, $downloadName, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    public function getSavedAcroFormState(Request $request, Document $document)
    {
        $validated = $request->validate([
            'session_id' => 'nullable|string',
        ]);

        $sessionId = is_string($validated['session_id'] ?? null)
            ? trim((string) $validated['session_id'])
            : '';

        $query = PdfAcroForm::query()
            ->where('document_id', $document->id);

        if ($sessionId !== '') {
            $query->where('sess_id', $sessionId);
        } else {
            $query->whereRaw('1 = 0');
        }

        $entries = $query
            ->orderBy('page_num')
            ->orderBy('updated_at')
            ->get()
            ->map(function (PdfAcroForm $record) {
                $entry = is_array($record->data) ? $record->data : [];
                $entry['db_state'] = $record->state;
                $entry['db_updated_at'] = optional($record->updated_at)?->toIso8601String();
                return $entry;
            })
            ->values();

        return response()->json([
            'success' => true,
            'session_id' => $sessionId !== '' ? $sessionId : null,
            'count' => $entries->count(),
            'entries' => $entries,
        ]);
    }

    public function convertToPdfA(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'level' => ['required', 'string', 'in:1b,2b,3b,2u'],
            'embed_fonts' => ['boolean'],
            'srgb_profile' => ['boolean'],
        ]);

        if ($response = $this->consumeMonthlyActionQuota($request)) {
            return $response;
        }

        $level = $validated['level'];
        $embedFonts = $validated['embed_fonts'] ?? true;
        $srgbProfile = $validated['srgb_profile'] ?? true;

        $inputPath = Storage::path($document->path);
        $tempOutputPath = Storage::path('documents/temp_pdfa_' . Str::uuid() . '.pdf');
        $pythonScript = base_path('python/pdf-editor/convert_to_pdfa.py');

        // Build command
        $command = sprintf(
            '%s %s %s %s --level %s %s %s --json 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($inputPath),
            escapeshellarg($tempOutputPath),
            escapeshellarg($level),
            $embedFonts ? '--embed-fonts' : '--no-embed-fonts',
            $srgbProfile ? '--srgb' : '--no-srgb'
        );

        $output = shell_exec($command);

        // Parse the JSON output from the Python script
        $result = null;
        if ($output) {
            // Find the JSON line in the output (skip any stderr warnings)
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                $decoded = json_decode(trim($line), true);
                if ($decoded !== null) {
                    $result = $decoded;
                    break;
                }
            }
        }

        if (!$result || !($result['success'] ?? false)) {
            // Clean up temp file if it exists
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }

            // Log failed PDF/A export
            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => "Convert to PDF/A-" . strtoupper($level),
                    'category' => 'pdfa_export',
                    'details' => ['level' => $level, 'embed_fonts' => $embedFonts, 'srgb_profile' => $srgbProfile, 'error' => $result['error'] ?? 'Unknown error'],
                    'document_id' => $document->id,
                    'status' => 'failed',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'PDF/A conversion failed. Output: ' . ($output ?? 'none'),
            ], 500);
        }

        if (!file_exists($tempOutputPath)) {
            // Log failed PDF/A export
            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => "Convert to PDF/A-" . strtoupper($level),
                    'category' => 'pdfa_export',
                    'details' => ['level' => $level, 'embed_fonts' => $embedFonts, 'srgb_profile' => $srgbProfile, 'error' => 'Output file not created'],
                    'document_id' => $document->id,
                    'status' => 'failed',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'PDF/A output file was not created',
            ], 500);
        }

        // Generate a download token and store temp file path in session
        $downloadToken = Str::uuid()->toString();
        $baseName = pathinfo($document->original_name, PATHINFO_FILENAME);
        $downloadName = $baseName . '_PDFA-' . strtoupper($level) . '.pdf';

        session()->put("pdfa_download_{$downloadToken}", [
            'path' => $tempOutputPath,
            'name' => $downloadName,
            'expires' => now()->addMinutes(10),
        ]);

        // Log successful PDF/A export
        if (Auth::check()) {
            UserActivity::create([
                'user_id' => Auth::id(),
                'action' => "Convert to PDF/A-" . strtoupper($level),
                'category' => 'pdfa_export',
                'details' => [
                    'level' => $level,
                    'embed_fonts' => $embedFonts,
                    'srgb_profile' => $srgbProfile,
                    'file_size' => filesize($tempOutputPath),
                    'compliance_status' => $result['report']['status'] ?? null,
                ],
                'document_id' => $document->id,
                'status' => 'success',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json([
            'success' => true,
            'download_token' => $downloadToken,
            'download_name' => $downloadName,
            'report' => $result['report'] ?? null,
            'warnings' => $result['warnings'] ?? [],
            'label' => $result['label'] ?? "PDF/A-{$level}",
        ]);
    }

    public function downloadPdfA(Request $request)
    {
        $token = $request->query('token');
        if (!$token) {
            abort(400, 'Missing download token');
        }

        $data = session()->pull("pdfa_download_{$token}");
        if (!$data || !isset($data['path'])) {
            abort(404, 'Download expired or not found');
        }

        if (now()->greaterThan($data['expires'])) {
            if (file_exists($data['path'])) {
                unlink($data['path']);
            }
            abort(410, 'Download link has expired');
        }

        if (!file_exists($data['path'])) {
            abort(404, 'File not found');
        }

        return response()->download($data['path'], $data['name'], [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    public function logExportActivity(Request $request, Document $document)
    {
        if (!$this->hasEditorAuthentication()) {
            return response()->json(['success' => false, 'message' => 'Not authenticated'], 401);
        }

        $validated = $request->validate([
            'action' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:50'],
            'details' => ['nullable', 'array'],
            'status' => ['required', 'string', 'in:success,failed'],
        ]);

        // Image exports are client-side conversions, so this endpoint is the
        // only reliable place to count them toward monthly PDF action quota.
        if (
            ($validated['status'] ?? null) === 'success'
            && ($validated['category'] ?? null) === 'image_export'
        ) {
            if ($response = $this->consumeMonthlyActionQuota($request)) {
                return $response;
            }
        }

        if (Auth::guard('web')->check()) {
            UserActivity::create([
                'user_id' => Auth::id(),
                'action' => $validated['action'],
                'category' => $validated['category'],
                'details' => $validated['details'] ?? null,
                'document_id' => $document->id,
                'status' => $validated['status'],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function convertToWord(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'layout' => ['required', 'string', 'in:flow,exact'],
            'include_images' => ['boolean'],
            'ocr' => ['boolean'],
        ]);

        if ($response = $this->consumeMonthlyActionQuota($request)) {
            return $response;
        }

        $layout = $validated['layout'];
        $includeImages = $validated['include_images'] ?? true;
        $ocr = $validated['ocr'] ?? false;

        $inputPath = Storage::path($document->path);
        $tempOutputPath = Storage::path('documents/temp_word_' . Str::uuid() . '.docx');
        $pythonScript = base_path('python/pdf-editor/convert_to_word.py');

        $command = sprintf(
            '%s %s %s %s --layout %s %s %s --json 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($inputPath),
            escapeshellarg($tempOutputPath),
            escapeshellarg($layout),
            $includeImages ? '--images' : '--no-images',
            $ocr ? '--ocr' : ''
        );

        $output = shell_exec($command);

        $result = null;
        if ($output) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                $decoded = json_decode(trim($line), true);
                if ($decoded !== null) {
                    $result = $decoded;
                    break;
                }
            }
        }

        if (!$result || !($result['success'] ?? false)) {
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }

            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => 'Convert to Word',
                    'category' => 'word_export',
                    'details' => ['layout' => $layout, 'include_images' => $includeImages, 'ocr' => $ocr, 'error' => $result['error'] ?? 'Unknown error'],
                    'document_id' => $document->id,
                    'status' => 'failed',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Word conversion failed. Output: ' . ($output ?? 'none'),
            ], 500);
        }

        if (!file_exists($tempOutputPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Word output file was not created',
            ], 500);
        }

        $downloadToken = Str::uuid()->toString();
        $baseName = pathinfo($document->original_name, PATHINFO_FILENAME);
        $downloadName = $baseName . '.docx';

        session()->put("converted_download_{$downloadToken}", [
            'path' => $tempOutputPath,
            'name' => $downloadName,
            'content_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'expires' => now()->addMinutes(10),
        ]);

        if (Auth::check()) {
            UserActivity::create([
                'user_id' => Auth::id(),
                'action' => 'Convert to Word',
                'category' => 'word_export',
                'details' => [
                    'layout' => $layout,
                    'include_images' => $includeImages,
                    'ocr' => $ocr,
                    'file_size' => filesize($tempOutputPath),
                ],
                'document_id' => $document->id,
                'status' => 'success',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json([
            'success' => true,
            'download_token' => $downloadToken,
            'download_name' => $downloadName,
        ]);
    }

    public function convertToExcel(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:tables,all'],
            'merge_cells' => ['boolean'],
            'sheet_per_page' => ['boolean'],
        ]);

        if ($response = $this->consumeMonthlyActionQuota($request)) {
            return $response;
        }

        $mode = $validated['mode'];
        $mergeCells = $validated['merge_cells'] ?? true;
        $sheetPerPage = $validated['sheet_per_page'] ?? true;

        $inputPath = Storage::path($document->path);
        $tempOutputPath = Storage::path('documents/temp_excel_' . Str::uuid() . '.xlsx');
        $pythonScript = base_path('python/pdf-editor/convert_to_excel.py');

        $command = sprintf(
            '%s %s %s %s --mode %s %s %s --json 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($inputPath),
            escapeshellarg($tempOutputPath),
            escapeshellarg($mode),
            $mergeCells ? '--merge-cells' : '--no-merge-cells',
            $sheetPerPage ? '--sheet-per-page' : '--single-sheet'
        );

        $output = shell_exec($command);

        $result = null;
        if ($output) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                $decoded = json_decode(trim($line), true);
                if ($decoded !== null) {
                    $result = $decoded;
                    break;
                }
            }
        }

        if (!$result || !($result['success'] ?? false)) {
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }

            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => 'Convert to Excel',
                    'category' => 'excel_export',
                    'details' => ['mode' => $mode, 'merge_cells' => $mergeCells, 'sheet_per_page' => $sheetPerPage, 'error' => $result['error'] ?? 'Unknown error'],
                    'document_id' => $document->id,
                    'status' => 'failed',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Excel conversion failed. Output: ' . ($output ?? 'none'),
            ], 500);
        }

        if (!file_exists($tempOutputPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Excel output file was not created',
            ], 500);
        }

        $downloadToken = Str::uuid()->toString();
        $baseName = pathinfo($document->original_name, PATHINFO_FILENAME);
        $downloadName = $baseName . '.xlsx';

        session()->put("converted_download_{$downloadToken}", [
            'path' => $tempOutputPath,
            'name' => $downloadName,
            'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'expires' => now()->addMinutes(10),
        ]);

        if (Auth::check()) {
            UserActivity::create([
                'user_id' => Auth::id(),
                'action' => 'Convert to Excel',
                'category' => 'excel_export',
                'details' => [
                    'mode' => $mode,
                    'merge_cells' => $mergeCells,
                    'sheet_per_page' => $sheetPerPage,
                    'file_size' => filesize($tempOutputPath),
                ],
                'document_id' => $document->id,
                'status' => 'success',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json([
            'success' => true,
            'download_token' => $downloadToken,
            'download_name' => $downloadName,
        ]);
    }

    public function splitPdf(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:each,specific,range,custom'],
            'page' => ['nullable', 'integer', 'min:1'],
            'from_page' => ['nullable', 'integer', 'min:1'],
            'to_page' => ['nullable', 'integer', 'min:1'],
            'pages' => ['nullable', 'string'],
        ]);

        if ($response = $this->consumeMonthlyActionQuota($request)) {
            return $response;
        }

        $mode = $validated['mode'];
        $inputPath = Storage::path($document->path);
        $outputDir = Storage::path('documents/split_' . Str::uuid());
        $pythonScript = base_path('python/pdf-editor/split_pdf.py');

        // Build command
        $command = escapeshellarg($pythonBinary) . ' ' . escapeshellarg($pythonScript)
            . ' ' . escapeshellarg($inputPath)
            . ' ' . escapeshellarg($outputDir)
            . ' --mode ' . escapeshellarg($mode);

        if ($mode === 'specific' && isset($validated['page'])) {
            $command .= ' --page ' . intval($validated['page']);
        }
        if ($mode === 'range') {
            if (isset($validated['from_page'])) {
                $command .= ' --from ' . intval($validated['from_page']);
            }
            if (isset($validated['to_page'])) {
                $command .= ' --to ' . intval($validated['to_page']);
            }
        }
        if ($mode === 'custom' && isset($validated['pages'])) {
            $command .= ' --pages ' . escapeshellarg($validated['pages']);
        }

        $command .= ' --json 2>&1';

        $output = shell_exec($command);

        // Parse JSON output
        $result = null;
        if ($output) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                $decoded = json_decode(trim($line), true);
                if ($decoded !== null) {
                    $result = $decoded;
                    break;
                }
            }
        }

        if (!$result || !($result['success'] ?? false)) {
            // Clean up output dir
            if (is_dir($outputDir)) {
                array_map('unlink', glob("$outputDir/*"));
                rmdir($outputDir);
            }

            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => "Split PDF ({$mode})",
                    'category' => 'split_export',
                    'details' => ['mode' => $mode, 'error' => $result['error'] ?? 'Unknown error'],
                    'document_id' => $document->id,
                    'status' => 'failed',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'PDF split failed. Output: ' . ($output ?? 'none'),
            ], 500);
        }

        $files = $result['files'] ?? [];
        if (empty($files)) {
            return response()->json([
                'success' => false,
                'message' => 'No output files were created',
            ], 500);
        }

        $baseName = pathinfo($document->original_name, PATHINFO_FILENAME);

        // For "each" mode with multiple files, create a ZIP
        if ($mode === 'each' && count($files) > 1) {
            $zipPath = Storage::path('documents/split_' . Str::uuid() . '.zip');
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
                return response()->json(['success' => false, 'message' => 'Failed to create ZIP archive'], 500);
            }
            foreach ($files as $file) {
                $zip->addFile($file['path'], $file['name']);
            }
            $zip->close();

            // Clean up individual files
            foreach ($files as $file) {
                if (file_exists($file['path'])) {
                    unlink($file['path']);
                }
            }
            if (is_dir($outputDir)) {
                @rmdir($outputDir);
            }

            $downloadToken = Str::uuid()->toString();
            $downloadName = $baseName . '_split_all_pages.zip';

            session()->put("converted_download_{$downloadToken}", [
                'path' => $zipPath,
                'name' => $downloadName,
                'content_type' => 'application/zip',
                'expires' => now()->addMinutes(10),
            ]);

            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => "Split PDF ({$mode})",
                    'category' => 'split_export',
                    'details' => ['mode' => $mode, 'file_count' => count($files), 'total_pages' => $result['total_pages']],
                    'document_id' => $document->id,
                    'status' => 'success',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json([
                'success' => true,
                'download_token' => $downloadToken,
                'download_name' => $downloadName,
                'file_count' => count($files),
                'total_pages' => $result['total_pages'],
            ]);
        }

        // Single file output
        $file = $files[0];
        $downloadToken = Str::uuid()->toString();
        $downloadName = $file['name'];

        session()->put("converted_download_{$downloadToken}", [
            'path' => $file['path'],
            'name' => $downloadName,
            'content_type' => 'application/pdf',
            'expires' => now()->addMinutes(10),
        ]);

        // Clean up output dir (file moved to session tracking)
        // Don't remove the file itself, just the dir if empty later
        if (is_dir($outputDir) && count(glob("$outputDir/*")) === 0) {
            @rmdir($outputDir);
        }

        if (Auth::check()) {
            UserActivity::create([
                'user_id' => Auth::id(),
                'action' => "Split PDF ({$mode})",
                'category' => 'split_export',
                'details' => ['mode' => $mode, 'file_count' => 1, 'total_pages' => $result['total_pages']],
                'document_id' => $document->id,
                'status' => 'success',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json([
            'success' => true,
            'download_token' => $downloadToken,
            'download_name' => $downloadName,
            'file_count' => 1,
            'total_pages' => $result['total_pages'],
        ]);
    }

    public function downloadConverted(Request $request)
    {
        $token = $request->query('token');
        if (!$token) {
            abort(400, 'Missing download token');
        }

        $data = session()->pull("converted_download_{$token}");
        if (!$data || !isset($data['path'])) {
            abort(404, 'Download expired or not found');
        }

        if (now()->greaterThan($data['expires'])) {
            if (file_exists($data['path'])) {
                unlink($data['path']);
            }
            abort(410, 'Download link has expired');
        }

        if (!file_exists($data['path'])) {
            abort(404, 'File not found');
        }

        return response()->download($data['path'], $data['name'], [
            'Content-Type' => $data['content_type'] ?? 'application/octet-stream',
        ])->deleteFileAfterSend(true);
    }

    private function consumeMonthlyUploadQuota(Request $request)
    {
        $user = $this->resolveQuotaUser();
        if (!$user) {
            return null;
        }

        $usage = $this->resolveMonthlyUsage($user);
        if ($usage->uploads_count >= self::MONTHLY_UPLOAD_LIMIT) {
            return redirect()
                ->back()
                ->withErrors("Monthly PDF upload limit reached (".self::MONTHLY_UPLOAD_LIMIT.").");
        }

        $usage->uploads_count = (int) $usage->uploads_count + 1;
        $usage->save();

        return null;
    }

    private function consumeMonthlyActionQuota(Request $request)
    {
        $user = $this->resolveQuotaUser();
        if (!$user) {
            return null;
        }

        $usage = $this->resolveMonthlyUsage($user);
        $unlimited = $this->hasUnlimitedPdfActions($user);
        $usage->has_unlimited_actions = $unlimited;

        if (!$unlimited && $usage->actions_count >= self::MONTHLY_ACTION_LIMIT) {
            return response()->json([
                'success' => false,
                'message' => 'Monthly PDF action limit reached (' . self::MONTHLY_ACTION_LIMIT . ').',
            ], 429);
        }

        $usage->actions_count = (int) $usage->actions_count + 1;
        $usage->save();

        return null;
    }

    private function resolveQuotaUser(): ?User
    {
        $user = Auth::user();
        return $user instanceof User ? $user : null;
    }

    private function resolveMonthlyUsage(User $user): UserPdfMonthlyUsage
    {
        return UserPdfMonthlyUsage::firstOrCreate(
            [
                'user_id' => $user->id,
                'month_start' => now()->startOfMonth()->toDateString(),
            ],
            [
                'uploads_count' => 0,
                'actions_count' => 0,
                'has_unlimited_actions' => $this->hasUnlimitedPdfActions($user),
            ]
        );
    }

    private function hasUnlimitedPdfActions(User $user): bool
    {
        return $user->hasActiveSubscription('pdf-editor');
    }
}
