<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCreditBalanceException;
use App\Jobs\ConvertDocumentExportJob;
use App\Jobs\ProcessUploadedDocumentJob;
use App\Models\Admin;
use App\Models\CreditTransaction;
use App\Models\Document;
use App\Models\DocumentConversion;
use App\Models\DocumentConversionSetting;
use App\Models\DocumentNote;
use App\Models\GuidedTemplate;
use App\Models\PdfAcroForm;
use App\Models\PdfGroup;
use App\Models\PdfState;
use App\Services\PdfAnnotationAssetService;
use App\Services\AdobePdfServices;
use App\Services\DocumentConversionPricing;
use App\Services\DocumentExportConversionService;
use App\Services\PdfDocumentMergeConflictException;
use App\Services\PdfDocumentMergeService;
use App\Services\PdfFitzExtractionNormalizer;
use App\Support\PdfAnnotationSuppression;
use App\Models\User;
use App\Models\UserActivity;
use App\Models\UserPdfMonthlyUsage;
use App\Http\Controllers\PdfTestController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    private const MONTHLY_UPLOAD_LIMIT = 100;
    private const MONTHLY_ACTION_LIMIT = 1000;
    private const PDF_ACRO_FORM_BASE_SESSION = '__document_acro_form__';
    private const SESSION_DOCUMENT_ACCESS_KEY = 'pdf_editor_accessible_document_ids';
    private const DOCUMENT_NOTE_PIN_COLORS = [
        '#2563eb',
        '#ef4444',
        '#f59e0b',
        '#10b981',
        '#8b5cf6',
        '#111827',
    ];
    private const DOCUMENT_NOTE_PIN_ICONS = [
        'note',
        'flag',
        'check',
        'alert',
        'star',
        'question',
    ];

    public function __construct()
    {
        $this->middleware(function (Request $request, Closure $next) {
            $routeDocument = $request->route('document');
            $routeName = (string) optional($request->route())->getName();

            if ($routeName === 'documents.ai' && $routeDocument !== null) {
                $this->authorizeAiRouteAccess($request, $routeDocument);
            } elseif ($routeDocument instanceof Document) {
                $this->authorizeDocumentAccess($request, $routeDocument);
            }

            return $next($request);
        });
    }

    private function sessionAccessibleDocumentIds(Request $request): array
    {
        return collect($request->session()->get(self::SESSION_DOCUMENT_ACCESS_KEY, []))
            ->map(static fn ($value) => (int) $value)
            ->filter(static fn (int $value) => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function rememberSessionAccessibleDocument(Request $request, Document $document): void
    {
        if ($document->id <= 0 || $this->documentHasPersistentOwner($document)) {
            return;
        }

        $documentIds = collect($this->sessionAccessibleDocumentIds($request))
            ->push((int) $document->id)
            ->unique()
            ->values()
            ->all();

        $request->session()->put(self::SESSION_DOCUMENT_ACCESS_KEY, $documentIds);
    }

    private function currentWebUserId(): ?int
    {
        $userId = Auth::guard('web')->id();

        return $userId !== null ? (int) $userId : null;
    }

    private function currentAdminId(): ?int
    {
        $adminId = Auth::guard('admin')->id();

        return $adminId !== null ? (int) $adminId : null;
    }

    private function currentEditorOwnership(): array
    {
        $webUserId = $this->currentWebUserId();
        if ($webUserId !== null) {
            return [
                'user_id' => $webUserId,
                'admin_id' => null,
            ];
        }

        $adminId = $this->currentAdminId();
        if ($adminId !== null) {
            return [
                'user_id' => null,
                'admin_id' => $adminId,
            ];
        }

        return [
            'user_id' => null,
            'admin_id' => null,
        ];
    }

    private function resolveDocumentOwnership(?Document $document = null): array
    {
        $documentUserId = $document?->user_id;
        if ($documentUserId !== null) {
            return [
                'user_id' => (int) $documentUserId,
                'admin_id' => null,
            ];
        }

        $documentAdminId = $document?->admin_id;
        if ($documentAdminId !== null) {
            return [
                'user_id' => null,
                'admin_id' => (int) $documentAdminId,
            ];
        }

        return $this->currentEditorOwnership();
    }

    private function documentOwnershipPayload(?Document $document = null): array
    {
        return $this->resolveDocumentOwnership($document);
    }

    private function documentHasPersistentOwner(Document $document): bool
    {
        return $document->user_id !== null || $document->admin_id !== null;
    }

    private function documentHasPdfPassword(Document $document): bool
    {
        return trim((string) $document->pdf_password_hash) !== '';
    }

    private function documentPdfUnlockSessionKey(Document $document): string
    {
        return 'pdf_document_unlock_' . $document->id;
    }

    private function issueDocumentPdfUnlockToken(Request $request, Document $document): string
    {
        $token = Str::random(64);
        $request->session()->put(
            $this->documentPdfUnlockSessionKey($document),
            hash('sha256', $token)
        );

        return $token;
    }

    private function forgetDocumentPdfUnlockToken(Request $request, Document $document): void
    {
        $request->session()->forget($this->documentPdfUnlockSessionKey($document));
    }

    private function requestHasDocumentPdfUnlock(Request $request, Document $document): bool
    {
        if (!$this->documentHasPdfPassword($document)) {
            return true;
        }

        $token = trim((string) (
            $request->query('pdf_unlock_token')
            ?: $request->header('X-PDF-Unlock-Token', '')
        ));
        $expectedHash = (string) $request->session()->get(
            $this->documentPdfUnlockSessionKey($document),
            ''
        );

        return $token !== ''
            && $expectedHash !== ''
            && hash_equals($expectedHash, hash('sha256', $token));
    }

    private function requireDocumentPdfUnlock(Request $request, Document $document): void
    {
        if (!$this->requestHasDocumentPdfUnlock($request, $document)) {
            abort(423, 'PDF password required.');
        }
    }

    private function claimSessionAccessibleDocuments(Request $request): void
    {
        static $claimed = false;

        if ($claimed) {
            return;
        }

        $claimed = true;
        $ownership = $this->currentEditorOwnership();
        if (($ownership['user_id'] ?? null) === null && ($ownership['admin_id'] ?? null) === null) {
            return;
        }

        $sessionDocumentIds = $this->sessionAccessibleDocumentIds($request);
        if (empty($sessionDocumentIds)) {
            return;
        }

        $unownedDocumentIds = Document::query()
            ->whereIn('id', $sessionDocumentIds)
            ->whereNull('user_id')
            ->whereNull('admin_id')
            ->pluck('id')
            ->map(static fn ($value) => (int) $value)
            ->all();

        if (empty($unownedDocumentIds)) {
            return;
        }

        DB::table('documents')
            ->whereIn('id', $unownedDocumentIds)
            ->update($ownership);

        if (Schema::hasColumn('pdf_state', 'admin_id')) {
            DB::table('pdf_state')
                ->whereIn('document_id', $unownedDocumentIds)
                ->whereNull('user_id')
                ->whereNull('admin_id')
                ->update(array_merge($ownership, ['user_email' => null]));
        }

        if (Schema::hasColumn('pdf_acro_form', 'admin_id')) {
            DB::table('pdf_acro_form')
                ->whereIn('document_id', $unownedDocumentIds)
                ->whereNull('user_id')
                ->whereNull('admin_id')
                ->update($ownership);
        }
    }

    private function canAccessDocument(Request $request, Document $document): bool
    {
        $this->claimSessionAccessibleDocuments($request);

        $webUserId = $this->currentWebUserId();
        if ($webUserId !== null && (int) $document->user_id === $webUserId) {
            return true;
        }

        $adminId = $this->currentAdminId();
        if ($adminId !== null && (int) $document->admin_id === $adminId) {
            return true;
        }

        if (!$this->documentHasPersistentOwner($document)) {
            if (app()->environment('local')) {
                return true;
            }
            return in_array((int) $document->id, $this->sessionAccessibleDocumentIds($request), true);
        }

        return false;
    }

    private function authorizeDocumentAccess(Request $request, Document $document): void
    {
        abort_unless($this->canAccessDocument($request, $document), 404);
    }

    private function authorizeAiRouteAccess(Request $request, mixed $routeDocument): void
    {
        $identifier = is_scalar($routeDocument) ? (string) $routeDocument : '';
        if ($identifier === '') {
            abort(404);
        }

        $aiDocument = \App\Models\AiDocument::with('document')->find($identifier);
        if ($aiDocument instanceof \App\Models\AiDocument && $aiDocument->document instanceof Document) {
            $this->authorizeDocumentAccess($request, $aiDocument->document);
            return;
        }

        $document = Document::query()->find($identifier);
        if ($document instanceof Document) {
            $this->authorizeDocumentAccess($request, $document);
            return;
        }

        abort(404);
    }

    private function applyAccessibleDocumentScope(Request $request, $query)
    {
        $this->claimSessionAccessibleDocuments($request);

        $webUserId = $this->currentWebUserId();
        $adminId = $this->currentAdminId();
        $sessionDocumentIds = $this->sessionAccessibleDocumentIds($request);

        $query->where(function ($scopedQuery) use ($webUserId, $adminId, $sessionDocumentIds) {
            if ($webUserId !== null) {
                $scopedQuery->where('user_id', $webUserId);
            }

            if ($adminId !== null) {
                $method = $webUserId !== null ? 'orWhere' : 'where';
                $scopedQuery->{$method}('admin_id', $adminId);
            }

            if (!empty($sessionDocumentIds)) {
                $method = ($webUserId !== null || $adminId !== null) ? 'orWhere' : 'where';
                $scopedQuery->{$method}(function ($sessionQuery) use ($sessionDocumentIds) {
                    $sessionQuery->whereNull('user_id')
                        ->whereNull('admin_id')
                        ->whereIn('id', $sessionDocumentIds);
                });
            }

            if ($webUserId === null && $adminId === null && empty($sessionDocumentIds)) {
                $scopedQuery->whereRaw('1 = 0');
            }
        });

        return $query;
    }

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

    private function resolvePdfStateUserId(?Document $document = null): ?int
    {
        return $this->resolveDocumentOwnership($document)['user_id'];
    }

    private function resolvePdfStateAdminId(?Document $document = null): ?int
    {
        return $this->resolveDocumentOwnership($document)['admin_id'];
    }

    private function resolvePdfStateOwnership(?Document $document = null): array
    {
        return $this->resolveDocumentOwnership($document);
    }

    private function applyPdfStateOwnershipScope($query, ?int $userId, ?int $adminId, string $sessionId = '')
    {
        if ($userId !== null) {
            $legacyEmail = trim((string) User::query()->whereKey($userId)->value('email'));

            return $query->where(function ($ownershipQuery) use ($userId, $legacyEmail, $sessionId) {
                $ownershipQuery->where('user_id', $userId);
                if ($legacyEmail !== '' || $sessionId !== '') {
                    $ownershipQuery->orWhere(function ($legacyQuery) use ($legacyEmail, $sessionId) {
                        $legacyQuery
                            ->whereNull('user_id')
                            ->whereNull('admin_id')
                            ->where(function ($identityQuery) use ($legacyEmail, $sessionId) {
                                if ($legacyEmail !== '') {
                                    $identityQuery->where('user_email', $legacyEmail);
                                }
                                if ($sessionId !== '') {
                                    $method = $legacyEmail !== '' ? 'orWhere' : 'where';
                                    $identityQuery->{$method}(function ($sessionQuery) use ($sessionId) {
                                        $sessionQuery
                                            ->whereNull('user_email')
                                            ->where('session_id', $sessionId);
                                    });
                                }
                            });
                    });
                }
            });
        }

        if ($adminId !== null) {
            $legacyEmail = trim((string) Admin::query()->whereKey($adminId)->value('email'));

            return $query->where(function ($ownershipQuery) use ($adminId, $legacyEmail, $sessionId) {
                $ownershipQuery->where('admin_id', $adminId);
                if ($legacyEmail !== '' || $sessionId !== '') {
                    $ownershipQuery->orWhere(function ($legacyQuery) use ($legacyEmail, $sessionId) {
                        $legacyQuery
                            ->whereNull('user_id')
                            ->whereNull('admin_id')
                            ->where(function ($identityQuery) use ($legacyEmail, $sessionId) {
                                if ($legacyEmail !== '') {
                                    $identityQuery->where('user_email', $legacyEmail);
                                }
                                if ($sessionId !== '') {
                                    $method = $legacyEmail !== '' ? 'orWhere' : 'where';
                                    $identityQuery->{$method}(function ($sessionQuery) use ($sessionId) {
                                        $sessionQuery
                                            ->whereNull('user_email')
                                            ->where('session_id', $sessionId);
                                    });
                                }
                            });
                    });
                }
            });
        }

        if ($sessionId !== '') {
            return $query->where('session_id', $sessionId);
        }

        return $query->whereRaw('1 = 0');
    }

    private function pdfStateOwnershipPayload(Document $document, string $sessionId, ?string $fallbackUserEmail = null): array
    {
        $ownership = $this->resolvePdfStateOwnership($document);
        $userId = $ownership['user_id'];
        $adminId = $ownership['admin_id'];

        return [
            'user_id' => $userId,
            'admin_id' => $adminId,
            'user_email' => ($userId !== null || $adminId !== null)
                ? null
                : ($fallbackUserEmail ?? $this->resolveEditorEmail()),
            'session_id' => $sessionId,
        ];
    }

    private function pdfEditorMode(): string
    {
        $mode = strtolower(trim((string) config('pdf_editor.mode', 'fitz_extraction')));

        return in_array($mode, ['fitz_extraction', 'annotation_base'], true)
            ? $mode
            : 'fitz_extraction';
    }

    private function usesAnnotationBaseMode(): bool
    {
        return $this->pdfEditorMode() === 'annotation_base';
    }

    private function applyPdfGroupOwnershipScope($query, ?int $userId, ?int $adminId, string $sessionId = '')
    {
        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        if ($adminId !== null) {
            return $query->where('admin_id', $adminId);
        }

        if ($sessionId !== '') {
            return $query->where('session_id', $sessionId);
        }

        return $query->whereRaw('1 = 0');
    }

    private function pdfGroupOwnershipPayload(Document $document, string $sessionId, ?string $fallbackUserEmail = null): array
    {
        return $this->pdfStateOwnershipPayload($document, $sessionId, $fallbackUserEmail);
    }

    private function normalizePromotedSourceRootKey(string $sourceKey): string
    {
        if (preg_match('/^(block-\d+-\d+)/', trim($sourceKey), $matches)) {
            return (string) $matches[1];
        }

        return trim($sourceKey);
    }

    private function parsePromotedSourceKeyDetails(string $sourceKey): array
    {
        $normalized = trim($sourceKey);
        if (!preg_match('/^block-(\d+)-(\d+)(?:-lines-(\d+)-(\d+))?(?:-.+)?$/', $normalized, $matches)) {
            return [
                'page_number' => null,
                'block_num' => null,
                'line_start' => null,
                'line_end' => null,
                'root_source_key' => $normalized,
            ];
        }

        return [
            'page_number' => (int) $matches[1],
            'block_num' => (int) $matches[2],
            'line_start' => isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : null,
            'line_end' => isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : null,
            'root_source_key' => 'block-' . (int) $matches[1] . '-' . (int) $matches[2],
        ];
    }

    protected function resolvePythonBinaryForPdfEditor(string|array|null $requiredModule = null): string
    {
        $requiredModules = array_values(array_filter(
            is_array($requiredModule) ? $requiredModule : [$requiredModule],
            static fn ($module) => is_string($module) && $module !== ''
        ));

        // Probing a candidate costs a full python start-up per call. The
        // interpreter cannot change within a request, so memoize per module
        // set — endpoints that shell out several times only pay it once.
        static $resolved = [];
        $cacheKey = implode('|', $requiredModules);
        if (isset($resolved[$cacheKey])) {
            return $resolved[$cacheKey];
        }

        $candidates = array_values(array_unique([
            base_path('.venv/bin/python'),
            base_path('venv/bin/python'),
            base_path('.venv/Scripts/python.exe'),
            base_path('venv/Scripts/python.exe'),
            base_path('python/venv/bin/python'),
            base_path('python/venv/Scripts/python.exe'),
            '/usr/bin/python3',
            'python3',
        ]));

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '/') && !is_executable($candidate)) {
                continue;
            }

            if ($requiredModules === []) {
                return $resolved[$cacheKey] = $candidate;
            }

            $invalidModules = array_filter(
                $requiredModules,
                static fn ($module) => !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $module)
            );
            if ($invalidModules !== []) {
                break;
            }

            $probeOutput = [];
            $probeExitCode = 1;
            $probeCommand = sprintf(
                '%s -c %s 2>&1',
                escapeshellarg($candidate),
                escapeshellarg(implode('; ', array_map(static fn ($module) => "import {$module}", $requiredModules)))
            );
            exec($probeCommand, $probeOutput, $probeExitCode);

            if ($probeExitCode === 0) {
                return $resolved[$cacheKey] = $candidate;
            }
        }

        return $resolved[$cacheKey] = 'python3';
    }

    private function annotationAssets(): PdfAnnotationAssetService
    {
        return app(PdfAnnotationAssetService::class);
    }

    private function fitzExtractionNormalizer(): PdfFitzExtractionNormalizer
    {
        return app(PdfFitzExtractionNormalizer::class);
    }

    private function normalizeFitzExtractionSnapshot($extractionRow): ?int
    {
        return $this->fitzExtractionNormalizer()->syncSnapshot($extractionRow);
    }

    private function findPinnedFitzExtractionSnapshotId(Document $document, iterable $stateRows, iterable $groupRows): ?int
    {
        foreach ([$stateRows, $groupRows] as $rows) {
            foreach ($rows as $row) {
                $snapshotId = is_numeric($row->pdf_extraction_fitz_id ?? null)
                    ? (int) $row->pdf_extraction_fitz_id
                    : null;
                if ($snapshotId !== null && $snapshotId > 0) {
                    return $snapshotId;
                }
            }
        }

        $latestFitzExtraction = $document->pdfExtractionsFitz()->latest()->first();
        if (!$latestFitzExtraction) {
            return null;
        }

        return $this->normalizeFitzExtractionSnapshot($latestFitzExtraction)
            ?? $this->fitzExtractionNormalizer()->snapshotId($latestFitzExtraction);
    }

    private function hasDocumentPreviewColumns(): bool
    {
        static $hasColumns = null;

        if ($hasColumns !== null) {
            return $hasColumns;
        }

        $hasColumns = Schema::hasColumn('documents', 'preview_image')
            && Schema::hasColumn('documents', 'preview_image_mime_type');

        return $hasColumns;
    }

    private function clearDocumentPreviewSnapshot(Document $document): void
    {
        if (!$this->hasDocumentPreviewColumns()) {
            return;
        }

        $timestamps = $document->timestamps;
        try {
            $document->timestamps = false;
            $document->preview_image = null;
            $document->preview_image_mime_type = null;
            $document->preview_image_width = null;
            $document->preview_image_height = null;
            $document->preview_image_updated_at = null;
            $document->saveQuietly();
        } finally {
            $document->timestamps = $timestamps;
        }
    }

    private function generatePdfPreviewJpeg(string $fullPath, int $targetWidth = 320, int $quality = 58): ?array
    {
        if (!is_file($fullPath)) {
            return null;
        }

        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
        $tmpOutputBase = tempnam(sys_get_temp_dir(), 'doc_preview_');
        $tmpScriptBase = tempnam(sys_get_temp_dir(), 'doc_preview_script_');

        if ($tmpOutputBase === false || $tmpScriptBase === false) {
            return null;
        }

        $tmpOutput = $tmpOutputBase . '.jpg';
        $tmpScript = $tmpScriptBase . '.py';
        @unlink($tmpOutputBase);
        @unlink($tmpScriptBase);

        $script = implode("\n", [
            'import fitz, sys',
            'src, dest, target_width, quality = sys.argv[1], sys.argv[2], max(int(sys.argv[3]), 64), max(int(sys.argv[4]), 20)',
            'doc = fitz.open(src)',
            'if doc.page_count < 1:',
            '    raise RuntimeError("Document has no pages")',
            'page = doc.load_page(0)',
            'rect = page.rect',
            'scale = float(target_width) / max(float(rect.width), 1.0)',
            'pix = page.get_pixmap(matrix=fitz.Matrix(scale, scale), alpha=False)',
            'pix.save(dest, output="jpeg", jpg_quality=quality)',
            'print(f"{pix.width}x{pix.height}")',
        ]);

        if (@file_put_contents($tmpScript, $script) === false) {
            return null;
        }

        $output = [];
        $returnCode = 1;
        $command = sprintf(
            '%s %s %s %s %d %d 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($tmpScript),
            escapeshellarg($fullPath),
            escapeshellarg($tmpOutput),
            $targetWidth,
            $quality
        );
        exec($command, $output, $returnCode);

        @unlink($tmpScript);

        if ($returnCode !== 0 || !is_file($tmpOutput)) {
            @unlink($tmpOutput);
            Log::warning('Failed to generate PDF preview snapshot', [
                'path' => $fullPath,
                'output' => implode("\n", $output),
                'return_code' => $returnCode,
            ]);
            return null;
        }

        $bytes = @file_get_contents($tmpOutput);
        $dimensions = @getimagesize($tmpOutput);
        @unlink($tmpOutput);

        if ($bytes === false || $dimensions === false) {
            return null;
        }

        return [
            'bytes' => $bytes,
            'mime_type' => 'image/jpeg',
            'width' => (int) ($dimensions[0] ?? 0),
            'height' => (int) ($dimensions[1] ?? 0),
        ];
    }

    private function generateRasterPreviewJpeg(string $fullPath, int $targetWidth = 320, int $quality = 72): ?array
    {
        if (!is_file($fullPath)) {
            return null;
        }

        $sourceBytes = @file_get_contents($fullPath);
        if ($sourceBytes === false) {
            return null;
        }

        $sourceImage = @imagecreatefromstring($sourceBytes);
        if (!$sourceImage) {
            return null;
        }

        try {
            $sourceWidth = imagesx($sourceImage);
            $sourceHeight = imagesy($sourceImage);
            if ($sourceWidth <= 0 || $sourceHeight <= 0) {
                return null;
            }

            $scale = min(1, $targetWidth / $sourceWidth);
            $previewWidth = max(1, (int) round($sourceWidth * $scale));
            $previewHeight = max(1, (int) round($sourceHeight * $scale));

            $previewImage = imagecreatetruecolor($previewWidth, $previewHeight);
            if (!$previewImage) {
                return null;
            }

            try {
                $background = imagecolorallocate($previewImage, 255, 255, 255);
                imagefill($previewImage, 0, 0, $background);
                imagecopyresampled(
                    $previewImage,
                    $sourceImage,
                    0,
                    0,
                    0,
                    0,
                    $previewWidth,
                    $previewHeight,
                    $sourceWidth,
                    $sourceHeight
                );

                ob_start();
                imagejpeg($previewImage, null, $quality);
                $jpegBytes = ob_get_clean();
            } finally {
                imagedestroy($previewImage);
            }
        } finally {
            imagedestroy($sourceImage);
        }

        if (!is_string($jpegBytes) || $jpegBytes === '') {
            return null;
        }

        return [
            'bytes' => $jpegBytes,
            'mime_type' => 'image/jpeg',
            'width' => $previewWidth,
            'height' => $previewHeight,
        ];
    }

    private function refreshDocumentPreviewSnapshot(Document $document): void
    {
        if (!$this->hasDocumentPreviewColumns()) {
            return;
        }

        $fullPath = Storage::path($document->path);
        $preview = null;
        $mimeType = strtolower((string) $document->mime_type);
        $extension = strtolower((string) pathinfo((string) $document->path, PATHINFO_EXTENSION));

        try {
            if ($mimeType === 'application/pdf' || $extension === 'pdf') {
                $preview = $this->generatePdfPreviewJpeg($fullPath);
            } elseif (str_starts_with($mimeType, 'image/')) {
                $preview = $this->generateRasterPreviewJpeg($fullPath);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to refresh document preview snapshot', [
                'document_id' => $document->id,
                'path' => $document->path,
                'error' => $e->getMessage(),
            ]);
            $preview = null;
        }

        if (!is_array($preview) || empty($preview['bytes'])) {
            $this->clearDocumentPreviewSnapshot($document);
            return;
        }

        $timestamps = $document->timestamps;
        try {
            $document->timestamps = false;
            $document->preview_image = base64_encode($preview['bytes']);
            $document->preview_image_mime_type = $preview['mime_type'] ?? 'image/jpeg';
            $document->preview_image_width = $preview['width'] ?? null;
            $document->preview_image_height = $preview['height'] ?? null;
            $document->preview_image_updated_at = now();
            $document->saveQuietly();
        } finally {
            $document->timestamps = $timestamps;
        }
    }

    public function processUploadedDocument(int $documentId, ?string $userEmail = null, ?string $sessionId = null): void
    {
        $document = Document::find($documentId);
        if (!$document) {
            Log::warning('Queued upload processing skipped missing document', [
                'document_id' => $documentId,
            ]);
            return;
        }

        $fullPath = Storage::path($document->path);
        if (!is_file($fullPath)) {
            Log::warning('Queued upload processing skipped missing PDF file', [
                'document_id' => $document->id,
                'path' => $document->path,
            ]);
            return;
        }

        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
        $resolvedUserEmail = is_string($userEmail) && trim($userEmail) !== ''
            ? trim($userEmail)
            : $this->resolveEditorEmail();
        $resolvedSessionId = is_string($sessionId) ? trim($sessionId) : '';

        $materializedAcroFormCount = $this->ensurePdfAcroFormMaterialized($document, $fullPath, $pythonBinary);
        [$extractionReturnCode, $extractionOutput] = $this->runFitzExtraction(
            $document,
            $fullPath,
            $resolvedUserEmail,
            $resolvedSessionId,
            $pythonBinary
        );

        if ($extractionReturnCode === 0) {
            $latestExtraction = $this->findLatestFitzExtraction($document->id, $resolvedUserEmail, $resolvedSessionId);
            if ($latestExtraction) {
                $materializedCount = $this->materializeFitzExtractionToPdfState($document, $latestExtraction);
                Log::info('Materialized queued upload extraction into pdf_state', [
                    'document_id' => $document->id,
                    'materialized_count' => $materializedCount,
                ]);
            } else {
                Log::warning('Queued Fitz extraction completed but no extraction row was found for upload materialization', [
                    'document_id' => $document->id,
                    'user_email' => $resolvedUserEmail,
                    'session_id' => $resolvedSessionId,
                ]);
            }
        } else {
            Log::warning('Queued upload-time Fitz extraction failed; overlay prep will retry', [
                'document_id' => $document->id,
                'python_binary' => $pythonBinary,
                'return_code' => $extractionReturnCode,
                'output' => implode("\n", $extractionOutput),
            ]);
        }

        Log::info('Materialized queued upload AcroForm rows', [
            'document_id' => $document->id,
            'materialized_count' => $materializedAcroFormCount,
        ]);

        $this->refreshDocumentPreviewSnapshot($document);
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

        return array_map(function ($annotation) use ($annotationAssets, $document) {
            if (!is_array($annotation)) {
                return $annotation;
            }

            $normalized = $this->normalizeTextAnnotationBackgroundForExport(
                $annotationAssets->normalizeForPersistence($document, $annotation)
            );

            // Mirror overwriteAnnotationText(): when a promoted (extracted-
            // source) text annotation arrives from the frontend Save/Download
            // path with a text value that differs from its captured source
            // text, flip the same dirty/typography flags the API endpoint
            // sets. Without this, ensureRichTextHtmlForMultiStyleSource()
            // short-circuits on !$isDirtyPromoted, the multi-span
            // richTextHtml is never synthesized, and apply_annotations_direct_new.py
            // renders the new string in a single-style fallback layout — so
            // the same edit produced via the API renders correctly while the
            // frontend Save+Download path renders with wrong spacing/weight.
            $normalized = $this->markPromotedTextDirtyForRender($normalized);

            return $this->ensureRichTextHtmlForMultiStyleSource($normalized);
        }, $annotations);
    }

    /**
     * For promoted text annotations (id `promoted_*` or
     * promotedFromExtraction=true) whose `text` no longer matches the
     * captured source baseline, set the flags the Python writer uses to
     * pick the redact+restamp + per-span typography branch. Mirrors the
     * flag set in overwriteAnnotationText().
     */
    private function markPromotedTextDirtyForRender(array $annotation): array
    {
        if (strtolower(trim((string) ($annotation['type'] ?? ''))) !== 'text') {
            return $annotation;
        }

        $isPromoted = $this->annotationFlagIsTruthy($annotation, 'promotedFromExtraction')
            || str_starts_with((string) ($annotation['id'] ?? ''), 'promoted_');
        if (!$isPromoted) {
            return $annotation;
        }

        $normalize = static function ($v): string {
            $s = preg_replace('/\s+/u', ' ', (string) ($v ?? '')) ?? '';
            return trim($s);
        };
        $text   = $normalize($annotation['text'] ?? '');
        $source = $normalize(
            $annotation['pdfjsSourceText']
            ?? $annotation['originalText']
            ?? ''
        );
        if ($text === '' || $source === '' || $text === $source) {
            return $annotation;
        }

        $annotation['promotedDirty']             = true;
        $annotation['savedTextOverlay']          = true;
        $annotation['preserveSourceTypography']  = true;
        if (empty($annotation['pdfjsEditorMode'])) {
            $annotation['pdfjsEditorMode']       = 'source';
        }
        return $annotation;
    }

    /**
     * For dirty-promoted text annotations whose source was rendered from
     * multiple styled sourceSpans (e.g. bold "5a" prefix + regular body),
     * synthesize a richTextHtml fragment from the current `text` so the
     * Python writer's rich-text pipeline stamps each run with the correct
     * weight/italic. Without this, save + download paths collapse the
     * whole replacement string to the dominant source span's font — and
     * since the bold source span's subset only contains "5a" glyphs, the
     * body characters (M, O, ...) render as .notdef tofu boxes.
     *
     * This mirrors the same synthesis already wired into
     * overwriteAnnotationText(), but applied at the persistence layer so
     * saveAnnotations + downloadAnnotatedPdf both benefit.
     */
    private function ensureRichTextHtmlForMultiStyleSource(array $annotation): array
    {
        if (strtolower(trim((string) ($annotation['type'] ?? ''))) !== 'text') {
            return $annotation;
        }

        $isDirtyPromoted = $this->annotationFlagIsTruthy($annotation, 'promotedDirty')
            || $this->annotationFlagIsTruthy($annotation, 'sourceExactTextEdited')
            || $this->annotationFlagIsTruthy($annotation, 'preserveSourceLayout')
            || $this->annotationFlagIsTruthy($annotation, 'preserveSourceTypography');
        if (!$isDirtyPromoted) {
            return $annotation;
        }

        $existingRich = trim((string) ($annotation['richTextHtml'] ?? ''));
        if ($existingRich === '') {
            $existingRich = trim((string) (
                $annotation['annotation_data']['richTextHtml'] ?? ''
            ));
        }
        $trustExistingRichText = $existingRich !== ''
            && (
                $this->annotationFlagIsTruthy($annotation, 'userForcedRichText')
                || trim((string) ($annotation['pdfjsEditorMode'] ?? '')) === 'rich'
                || (
                    isset($annotation['annotation_data']) && is_array($annotation['annotation_data'])
                    && (
                        $this->annotationFlagIsTruthy($annotation['annotation_data'], 'userForcedRichText')
                        || trim((string) ($annotation['annotation_data']['pdfjsEditorMode'] ?? '')) === 'rich'
                    )
                )
            );
        if ($trustExistingRichText) {
            return $annotation;
        }

        $text = (string) ($annotation['text'] ?? '');
        if ($text === '') {
            return $annotation;
        }

        $synthesized = $this->synthesizeRichTextFromSourceSpans($annotation, $text);
        if ($synthesized === null) {
            if ($existingRich !== '') {
                return $annotation;
            }
            return $annotation;
        }

        $annotation['richTextHtml'] = $synthesized['html'];
        // NOTE: do NOT also rewrite `text` to match the padded HTML chunk
        // sequence — the writer scopes its glyph-coverage check against
        // ann["text"], and adding spaces there has been observed to push
        // the resolver into a single-run fallback that picks the dominant
        // (often bold-subset) face for the whole string, surfacing tofu
        // boxes for any glyph (e.g. M / O / x) that the bold subset never
        // covered in the source PDF. Leaving the canonical text alone keeps
        // the per-span coverage check in the rich branch intact.
        // Mirror the overwriteAnnotationText() path: tell the writer to keep
        // using the source-resolved embedded font (e.g. HelveticaNeueLTStd-Bd
        // / -Roman) instead of falling back to bundled Arimo when promoted
        // text is restamped. Without this the synthesized richTextHtml runs
        // get the right *weight* but the wrong *family*. Also flip the
        // pdfjs source-overlay flags so the writer enters the rich-text
        // path that consumes richTextHtml — without these the writer falls
        // back to the dirty-promoted layout that collapses the whole string
        // to a single-style span.
        $annotation['preserveSourceTypography'] = true;
        $annotation['savedTextOverlay'] = true;
        if (!isset($annotation['pdfjsEditorMode']) || $annotation['pdfjsEditorMode'] === '') {
            $annotation['pdfjsEditorMode'] = 'source';
        }
        if (isset($annotation['annotation_data']) && is_array($annotation['annotation_data'])) {
            $annotation['annotation_data']['richTextHtml'] = $synthesized['html'];
            $annotation['annotation_data']['preserveSourceTypography'] = true;
            $annotation['annotation_data']['savedTextOverlay'] = true;
            if (!isset($annotation['annotation_data']['pdfjsEditorMode'])
                || $annotation['annotation_data']['pdfjsEditorMode'] === '') {
                $annotation['annotation_data']['pdfjsEditorMode'] = 'source';
            }
        }
        return $annotation;
    }

    private function normalizeTextAnnotationBackgroundForExport(array $annotation): array
    {
        if (strtolower(trim((string) ($annotation['type'] ?? ''))) !== 'text') {
            return $annotation;
        }

        $background = strtolower(trim((string) ($annotation['backgroundColor'] ?? '')));
        $backgroundExplicit = filter_var(
            $annotation['backgroundColorExplicit'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        if ($background === '' || $background === 'transparent') {
            $annotation['backgroundColor'] = 'transparent';
            return $annotation;
        }

        if (!$backgroundExplicit && in_array($background, ['#fff', '#ffffff', 'white'], true)) {
            $annotation['backgroundColor'] = 'transparent';
        }

        return $annotation;
    }

    private function isDurablePromotedAnnotation(array $annotation): bool
    {
        return !empty($annotation['promotedFromExtraction'])
            && trim((string) ($annotation['promotedSourceKey'] ?? '')) !== '';
    }

    private function annotationFlagIsTruthy(array $annotation, string $key): bool
    {
        return filter_var($annotation[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * When overwriting text on a multi-span source annotation (e.g. an IRS-
     * form label whose original is "5b City, state, and ZIP code..." split
     * across a bold "5b" + regular body span), the writer otherwise picks
     * the dominant (widest) span's typography and stamps the entire new
     * string in that single weight — losing the bold prefix.
     *
     * This helper detects that pattern and synthesizes a richTextHtml
     * fragment that preserves per-span weight/italic on the new text by
     * splitting at whitespace boundaries that mirror the source span split.
     * Returns null when the pattern doesn't apply (single span, uniform
     * styles, no whitespace boundary in new text, etc.).
     */
    private function synthesizeRichTextFromSourceSpans(array $annotation, string $newText): ?array
    {
        $spans = $annotation['sourceSpans'] ?? null;
        if (!is_array($spans) || count($spans) < 2) {
            return null;
        }

        // Normalize spans to (text, bold, italic, baseline_y) tuples and drop
        // anything that doesn't look like text. We only handle the single-
        // visual-line case (all spans share the same baseline) for now; multi-
        // line source blocks would need a far more invasive split heuristic.
        $normalized = [];
        $baselineY  = null;
        foreach ($spans as $span) {
            if (!is_array($span)) continue;
            $text = trim((string) ($span['text'] ?? ''));
            if ($text === '') continue;
            $bbox = $span['bbox'] ?? null;
            if (!is_array($bbox) || count($bbox) < 4) continue;
            $y = (float) $bbox[1];
            if ($baselineY === null) {
                $baselineY = $y;
            } elseif (abs($y - $baselineY) > 2.0) {
                return null; // multi-line — bail out
            }
            $weight = (int) ($span['font_weight'] ?? ($span['bold'] ?? false ? 700 : 400));
            if ($weight <= 0) {
                $weight = $span['bold'] ?? false ? 700 : 400;
            }
            $italic = filter_var(
                $span['italic'] ?? ($span['font_style'] ?? '') === 'italic',
                FILTER_VALIDATE_BOOLEAN
            );
            $fontSize = (float) ($span['font_size'] ?? $span['fontSize'] ?? 0);
            if ($fontSize <= 0) $fontSize = 8.0;
            $spaceWidth = (float) ($span['space_width'] ?? 0);
            if ($spaceWidth <= 0) $spaceWidth = $fontSize * 0.278; // Helvetica space ratio
            $fontSourceName = trim((string) ($span['embedded_font_name'] ?? $span['font'] ?? ''));
            $fontFamily = trim((string) ($span['embedded_font_family'] ?? $span['font'] ?? $fontSourceName));
            $normalized[] = [
                'text'        => $text,
                'bold'        => $weight >= 600,
                'italic'      => $italic,
                'x0'          => (float) $bbox[0],
                'font_size'   => $fontSize,
                'space_width' => $spaceWidth,
                'font_family' => $fontFamily,
                'font_source_name' => $fontSourceName,
            ];
        }
        if (count($normalized) < 2) return null;

        // Sort by x position so spans are in visual reading order.
        usort($normalized, fn ($a, $b) => $a['x0'] <=> $b['x0']);

        // Compute visible-whitespace gap (in space-glyph count) between
        // consecutive source spans. The source PDF positions spans with
        // content-stream moves rather than literal spaces (e.g. "2" at
        // x=54.4 then "Trade…" at x=74.42, with no whitespace chars in
        // either span). The writer's rich-branch parser preserves bare
        // whitespace between styled <span>s as standalone runs (see
        // overwriteAnnotationText/promoted_1_63 where 8 literal spaces
        // between bold "12c" and roman "County…" rendered the correct
        // visual gap), so we emit the gap as N regular spaces sized from
        // the source's own space_width.
        $gapSpaces = [];
        for ($i = 0; $i < count($normalized) - 1; $i++) {
            $cur = $normalized[$i];
            $next = $normalized[$i + 1];
            $glyphWidth = mb_strlen($cur['text']) * $cur['font_size'] * 0.5;
            $visibleGap = ($next['x0'] - $cur['x0']) - $glyphWidth;
            $sw = max(0.1, $cur['space_width']);
            $n = (int) round($visibleGap / $sw);
            $gapSpaces[$i] = max(1, $n);
        }

        // If every span has identical style, nothing to preserve.
        $signatures = array_map(
            fn ($s) => ($s['bold'] ? '1' : '0') . ':' . ($s['italic'] ? '1' : '0'),
            $normalized
        );
        if (count(array_unique($signatures)) < 2) return null;

        $n = count($normalized);
        $normalizeText = static function ($value): string {
            $text = preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '';
            return trim($text);
        };
        $tokensFor = static function ($value) use ($normalizeText): array {
            $text = $normalizeText($value);
            if ($text === '') {
                return [];
            }
            $tokens = preg_split('/\s+/u', $text);
            return is_array($tokens) ? array_values(array_filter($tokens, static fn ($token) => $token !== '')) : [];
        };
        $startsWithTokens = static function (array $haystack, array $needle): bool {
            if (count($needle) > count($haystack)) {
                return false;
            }
            foreach ($needle as $idx => $token) {
                if (($haystack[$idx] ?? null) !== $token) {
                    return false;
                }
            }
            return true;
        };
        $endsWithTokens = static function (array $haystack, array $needle): bool {
            $needleCount = count($needle);
            if ($needleCount > count($haystack)) {
                return false;
            }
            if ($needleCount === 0) {
                return true;
            }
            $offset = count($haystack) - $needleCount;
            foreach ($needle as $idx => $token) {
                if (($haystack[$offset + $idx] ?? null) !== $token) {
                    return false;
                }
            }
            return true;
        };
        $spanTokens = array_map(static fn ($span) => $tokensFor($span['text'] ?? ''), $normalized);
        $newTokens = $tokensFor($newText);
        $aligned = null;

        // Prefer preserving the original physical source-span boundaries.
        // Example: promoted_1_9 is stored as three source spans at three x
        // positions: "10" | "Reason for applying" | "(check only one box)".
        // When the user edits only the field number to "55", splitting the
        // new string by the first three words produces the bad layout
        // "55" | "Reason" | "for applying (check...)". Match the unchanged
        // prefix/suffix source tokens instead so only the edited span changes.
        for ($changedIdx = 0; $changedIdx < $n && $aligned === null; $changedIdx++) {
            $prefixTokens = [];
            for ($i = 0; $i < $changedIdx; $i++) {
                array_push($prefixTokens, ...$spanTokens[$i]);
            }
            $suffixTokens = [];
            for ($i = $changedIdx + 1; $i < $n; $i++) {
                array_push($suffixTokens, ...$spanTokens[$i]);
            }
            if (!$startsWithTokens($newTokens, $prefixTokens) || !$endsWithTokens($newTokens, $suffixTokens)) {
                continue;
            }
            $changedTokenCount = count($newTokens) - count($prefixTokens) - count($suffixTokens);
            if ($changedTokenCount <= 0) {
                continue;
            }
            $changedTokens = array_slice($newTokens, count($prefixTokens), $changedTokenCount);
            $candidate = [];
            foreach ($normalized as $idx => $span) {
                $candidate[] = [
                    'text' => $idx === $changedIdx ? implode(' ', $changedTokens) : $normalizeText($span['text'] ?? ''),
                    'sep'  => '',
                ];
            }
            $aligned = $candidate;
        }

        if ($aligned === null) {
            // Fallback for broader edits: split new text at whitespace runs
            // into exactly N chunks (one per span). preg_split with
            // PREG_SPLIT_DELIM_CAPTURE preserves separators so we can re-emit
            // them between styled <span>s.
            $parts = preg_split('/(\s+)/u', $newText, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (!is_array($parts)) return null;
            $wordChunks = [];
            for ($i = 0; $i < count($parts); $i += 2) {
                $wordChunks[] = [
                    'text' => $parts[$i] ?? '',
                    'sep'  => $parts[$i + 1] ?? '',
                ];
            }
            if (count($wordChunks) < $n) return null;

            $aligned = array_slice($wordChunks, 0, $n - 1);
            $tail    = array_slice($wordChunks, $n - 1);
            $tailText = '';
            foreach ($tail as $idx => $t) {
                $tailText .= $t['text'];
                if ($idx !== count($tail) - 1) {
                    $tailText .= $t['sep'];
                }
            }
            $aligned[] = ['text' => $tailText, 'sep' => ''];
        }

        $html = '';
        $plain = '';
        foreach ($normalized as $idx => $span) {
            $chunk = $aligned[$idx]['text'];
            $sep   = $aligned[$idx]['sep'];
            $styles = [
                'font-weight:' . ($span['bold'] ? '700' : '400'),
                'font-style:' . ($span['italic'] ? 'italic' : 'normal'),
            ];
            $fontSourceName = trim((string) ($span['font_source_name'] ?? $span['font_family'] ?? ''));
            if ($fontSourceName !== '') {
                $safeFontSourceName = preg_replace('/[^A-Za-z0-9 _+.,-]/', '', $fontSourceName) ?? '';
                if ($safeFontSourceName !== '') {
                    $styles[] = 'font-family:&quot;' . htmlspecialchars($safeFontSourceName, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '&quot;';
                }
            }
            $escaped = htmlspecialchars($chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html .= '<span style="' . implode(';', $styles) . '">' . $escaped . '</span>';
            $plain .= $chunk;
            if ($idx < count($normalized) - 1) {
                // Emit the positional gap as bare regular spaces between
                // styled <span>s. The Python writer's rich-branch parser
                // captures bare whitespace between spans as a standalone
                // run, and the writer emits each run with embedded-font
                // metrics so multiple spaces actually consume their full
                // width (unlike MuPDF htmlbox which would collapse them).
                $count = $gapSpaces[$idx] ?? 1;
                $gap = str_repeat(' ', $count);
                $html .= $gap;
                $plain .= $gap;
            } elseif ($sep !== '') {
                $html .= htmlspecialchars($sep, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $plain .= $sep;
            }
        }
        return ['html' => $html, 'text' => $plain];
    }

    private function isPdfjsVisibleExportAnnotation(array $annotation): bool
    {
        $type = strtolower(trim((string) ($annotation['type'] ?? '')));
        if (!in_array($type, ['text', 'image', 'signature', 'shape', 'field'], true)) {
            return false;
        }
        $state = strtolower(trim((string) ($annotation['db_state'] ?? $annotation['state'] ?? '')));
        if ($state === 'deleted' && !$this->annotationFlagIsTruthy($annotation, 'pdfjsDeleted')) {
            return false;
        }
        $isPromoted = $this->annotationFlagIsTruthy($annotation, 'promotedFromExtraction')
            || str_starts_with(trim((string) ($annotation['id'] ?? '')), 'promoted_');
        if ($isPromoted) {
            return $this->promotedAnnotationShouldRenderAsOverlay($annotation);
        }

        return !$this->annotationFlagIsTruthy($annotation, 'promotedFromExtraction');
    }

    private function promotedAnnotationShouldRenderAsOverlay(array $annotation): bool
    {
        if ($this->annotationFlagIsTruthy($annotation, 'pdfjsDeleted')) {
            return false;
        }

        $text = preg_replace('/\s+/u', ' ', trim((string) ($annotation['text'] ?? ''))) ?? '';
        $sourceText = preg_replace(
            '/\s+/u',
            ' ',
            trim((string) ($annotation['pdfjsSourceText'] ?? $annotation['originalText'] ?? ''))
        ) ?? '';
        $textChanged = $text !== '' && $sourceText !== '' && $text !== $sourceText;

        $explicitlyDirty = $textChanged
            || $this->annotationFlagIsTruthy($annotation, 'promotedDirty')
            || $this->annotationFlagIsTruthy($annotation, 'userAuthored')
            || $this->annotationFlagIsTruthy($annotation, 'styleDirty')
            || $this->annotationFlagIsTruthy($annotation, 'userForcedRichText')
            || $this->annotationFlagIsTruthy($annotation, 'movedTextOverlay')
            || $this->annotationFlagIsTruthy($annotation, 'promotedReflowEnabled')
            || trim((string) ($annotation['richTextHtml'] ?? '')) !== '';
        if ($explicitlyDirty) {
            return true;
        }

        return strtolower(trim((string) ($annotation['pdfjsEditorMode'] ?? ''))) === 'rich'
            && !$this->promotedAnnotationIsMultiLine($annotation);
    }

    private function promotedAnnotationIsMultiLine(array $annotation): bool
    {
        if (count((array) ($annotation['sourceLineBBoxes'] ?? [])) > 1) {
            return true;
        }

        $text = (string) (
            $annotation['pdfjsSourceText']
            ?? $annotation['originalText']
            ?? $annotation['text']
            ?? ''
        );

        return count(array_filter(
            preg_split('/\R/u', $text) ?: [],
            static fn ($line) => trim((string) $line) !== ''
        )) > 1;
    }

    private function filterAnnotationsForPdfjsVisibleExport(array $annotations): array
    {
        return array_values(array_filter(
            $annotations,
            fn ($annotation) => is_array($annotation) && $this->isPdfjsVisibleExportAnnotation($annotation)
        ));
    }

    private function durablePdfStateIdentityKeyFromAnnotation(array $annotation): string
    {
        if ($this->isDurablePromotedAnnotation($annotation)) {
            $sourceKey = trim((string) $annotation['promotedSourceKey']);
            // Multiple annotations can legitimately share the same promoted
            // source block (a primary `promoted_X_Y` plus per-line
            // `promoted_X_Y_row_*` siblings produced when the source block
            // wraps onto several lines). Including the annotation id in the
            // identity key prevents these siblings from colliding during
            // upsertPdfStateSessionSnapshot, where the second/third sibling
            // would otherwise overwrite the row that just received the user's
            // edited text.
            $annotationId = trim((string) ($annotation['id'] ?? ''));
            return $annotationId !== ''
                ? 'promoted:' . $sourceKey . ':' . $annotationId
                : 'promoted:' . $sourceKey;
        }

        $annotationId = trim((string) ($annotation['id'] ?? ''));
        return $annotationId !== '' ? ('id:' . $annotationId) : '';
    }

    private function durablePdfStateIdentityKeyFromRecord(PdfState $record): string
    {
        $annotation = is_array($record->annotation_data) ? $record->annotation_data : [];
        return $this->durablePdfStateIdentityKeyFromAnnotation($annotation);
    }

    private function pdfStateRecordHasAnnotationDebug(PdfState $record): bool
    {
        $debug = $record->annotation_debug;
        if (is_string($debug)) {
            $decoded = json_decode($debug, true);
            $debug = is_array($decoded) ? $decoded : null;
        }
        if (is_array($debug)) {
            if (trim((string) ($debug['note'] ?? '')) !== '') {
                return true;
            }
            if (!empty($debug['mask']) && is_array($debug['mask'])) {
                return true;
            }
            if (!empty($debug['images']) && is_array($debug['images'])) {
                return true;
            }
            if (!empty($debug['updated_at'])) {
                return true;
            }
        }

        $annotation = is_array($record->annotation_data) ? $record->annotation_data : [];
        $embeddedDebug = $annotation['_debug'] ?? null;
        if (!is_array($embeddedDebug)) {
            return false;
        }

        return trim((string) ($embeddedDebug['note'] ?? '')) !== ''
            || (!empty($embeddedDebug['mask']) && is_array($embeddedDebug['mask']))
            || (!empty($embeddedDebug['images']) && is_array($embeddedDebug['images']))
            || !empty($embeddedDebug['updated_at']);
    }

    private function promotedSuppressionAnnotationId(string $sourceKey): string
    {
        return 'deleted_promoted:' . trim($sourceKey);
    }

    private function isPromotedSuppressionRecord(PdfState $record): bool
    {
        $annotationData = is_array($record->annotation_data) ? $record->annotation_data : [];
        return (
            filter_var(
                $annotationData['_promotedSuppression'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ) && filter_var(
                $annotationData['_explicitPromotedDelete'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            )
        ) || str_starts_with(trim((string) ($annotationData['id'] ?? '')), '__deleted_promoted__')
            || str_starts_with(trim((string) ($annotationData['id'] ?? '')), 'deleted_promoted:');
    }

    private function findExistingPdfStateRecordForAnnotation(
        Document $document,
        string $sessionId,
        array $annotation
    ): ?PdfState {
        $ownership = $this->resolvePdfStateOwnership($document);
        $query = PdfState::query()
            ->where('document_id', $document->id)
            ->where('state', '!=', 'deleted')
            ->where('state', '!=', 'extracted');
        $this->applyPdfStateOwnershipScope($query, $ownership['user_id'], $ownership['admin_id'], $sessionId);

        if ($this->isDurablePromotedAnnotation($annotation)) {
            $sourceKey = trim((string) ($annotation['promotedSourceKey'] ?? ''));
            $annotationId = trim((string) ($annotation['id'] ?? ''));
            // Prefer matching by both source key + annotation id so that
            // multi-line promoted siblings each resolve to their own row.
            // Fall back to source-key-only when an id is missing or when no
            // id-qualified row exists yet (legacy rows persisted before the
            // identity-key collision fix only carried the source key).
            if ($annotationId !== '') {
                $exact = (clone $query)
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.promotedSourceKey')) = ?", [$sourceKey])
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.id')) = ?", [$annotationId])
                    ->orderByDesc('updated_at')
                    ->first();
                if ($exact) {
                    return $exact;
                }
            }
            return $query
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.promotedSourceKey')) = ?", [$sourceKey])
                ->orderByDesc('updated_at')
                ->first();
        }

        $annotationId = trim((string) ($annotation['id'] ?? ''));
        if ($annotationId === '') {
            return null;
        }

        return $query
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.id')) = ?", [$annotationId])
            ->orderByDesc('updated_at')
            ->first();
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

        $ownership = $this->resolvePdfStateOwnership($document);
        $normalizedAnnotations = array_values(array_filter($annotations, 'is_array'));
        $annotationIds = array_values(array_filter(array_map(
            static fn (array $annotation) => is_string($annotation['id'] ?? null)
                ? trim((string) $annotation['id'])
                : '',
            $normalizedAnnotations
        )));

        DB::transaction(function () use ($document, $sessionId, $normalizedAnnotations, $annotationIds, $state, $ownership) {
            $existingRowsQuery = PdfState::query()
                ->where('document_id', $document->id)
                ->where('state', '!=', 'deleted')
                ->where('state', '!=', 'extracted');
            $this->applyPdfStateOwnershipScope($existingRowsQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
            $existingRows = $existingRowsQuery
                ->get()
                ->keyBy(fn (PdfState $record) => $this->durablePdfStateIdentityKeyFromRecord($record));

            $seenIds = [];
            foreach ($normalizedAnnotations as $annotation) {
                $annotationId = $this->durablePdfStateIdentityKeyFromAnnotation($annotation);
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
                        'session_id' => $sessionId,
                        'user_id' => $ownership['user_id'],
                        'admin_id' => $ownership['admin_id'],
                        'user_email' => ($ownership['user_id'] !== null || $ownership['admin_id'] !== null)
                            ? null
                            : $existing->user_email,
                        'state' => $state,
                    ]);
                } else {
                    PdfState::create([
                        'document_id' => $document->id,
                        'page_number' => $pageIndex,
                        'annotation_data' => $annotation,
                        'state' => $state,
                        ...$this->pdfStateOwnershipPayload($document, $sessionId),
                    ]);
                }

                $seenIds[$annotationId] = true;
            }

            $staleIds = [];
            foreach ($existingRows as $existingId => $record) {
                if ($existingId === '' || isset($seenIds[$existingId])) {
                    continue;
                }
                if ($this->pdfStateRecordHasAnnotationDebug($record)) {
                    continue;
                }
                $staleIds[] = $record->id;
            }

            if (!empty($staleIds)) {
                $staleRowsQuery = PdfState::query()
                    ->where('document_id', $document->id)
                    ->whereIn('id', $staleIds);
                $this->applyPdfStateOwnershipScope($staleRowsQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
                $staleRowsQuery->delete();
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
        array $ownership,
        string $state = 'saved'
    ): int {
        if ($sessionId === '') {
            return 0;
        }

        $normalizedEntries = $this->normalizeAcroFormEntriesForPersistence($entries);
        usort($normalizedEntries, static function (array $left, array $right): int {
            return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
        });
        $keys = array_values(array_filter(array_map(
            static fn (array $entry) => trim((string) ($entry['key'] ?? '')),
            $normalizedEntries
        )));

        $runUpsert = function () use ($document, $sessionId, $normalizedEntries, $ownership, $state, $keys) {
            foreach ($normalizedEntries as $entry) {
                $fieldKey = trim((string) ($entry['key'] ?? ''));
                if ($fieldKey === '') {
                    continue;
                }

                $existingQuery = PdfAcroForm::query()
                    ->where('document_id', $document->id)
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.key')) = ?", [$fieldKey]);
                if (($ownership['user_id'] ?? null) !== null) {
                    $existingQuery->where('user_id', $ownership['user_id']);
                } elseif (($ownership['admin_id'] ?? null) !== null) {
                    $existingQuery->where('admin_id', $ownership['admin_id']);
                } else {
                    $existingQuery->where('sess_id', $sessionId);
                }
                $existing = $existingQuery->first();

                $payload = [
                    'user_id' => $ownership['user_id'] ?? null,
                    'admin_id' => $ownership['admin_id'] ?? null,
                    'sess_id' => $sessionId,
                    'page_num' => isset($entry['pageIndex']) && $entry['pageIndex'] !== null ? (int) $entry['pageIndex'] : null,
                    'data' => $entry,
                    'state' => $state,
                ];

                if ($existing) {
                    if ($this->pdfAcroFormPayloadChanged($existing, $payload)) {
                        $existing->update($payload);
                    }
                } else {
                    PdfAcroForm::create(array_merge($payload, [
                        'document_id' => $document->id,
                    ]));
                }
            }

            $cleanupQuery = PdfAcroForm::query()
                ->where('document_id', $document->id);
            if (($ownership['user_id'] ?? null) !== null) {
                $cleanupQuery->where('user_id', $ownership['user_id']);
            } elseif (($ownership['admin_id'] ?? null) !== null) {
                $cleanupQuery->where('admin_id', $ownership['admin_id']);
            } else {
                $cleanupQuery->where('sess_id', $sessionId);
            }

            if (empty($keys)) {
                $cleanupQuery->delete();
            } else {
                $quotedKeys = implode(',', array_fill(0, count($keys), '?'));
                $cleanupQuery
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.key')) NOT IN ({$quotedKeys})", $keys)
                    ->delete();
            }
        };

        $this->runPdfAcroFormTransactionWithRetry($runUpsert);

        return count($normalizedEntries);
    }

    private function pdfAcroFormPayloadChanged(PdfAcroForm $existing, array $payload): bool
    {
        foreach (['user_id', 'admin_id', 'sess_id', 'page_num', 'state'] as $field) {
            if (($existing->{$field} ?? null) != ($payload[$field] ?? null)) {
                return true;
            }
        }

        return $existing->data != ($payload['data'] ?? null);
    }

    private function runPdfAcroFormTransactionWithRetry(callable $callback, int $attempts = 5): void
    {
        $attempts = max(1, $attempts);

        for ($attempt = 1; $attempt <= $attempts; $attempt += 1) {
            try {
                DB::transaction($callback, 1);
                return;
            } catch (QueryException $exception) {
                if (!$this->isRetryableDatabaseConcurrencyError($exception) || $attempt === $attempts) {
                    throw $exception;
                }

                usleep(random_int(25_000, 125_000) * $attempt);
            }
        }
    }

    private function isRetryableDatabaseConcurrencyError(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '40001'
            || in_array($driverCode, [1205, 1213], true);
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
                    'admin_id' => $document->admin_id,
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

        $prepared = array_values(array_filter(array_map(function ($annotation) use ($annotationAssets) {
            if (!is_array($annotation)) {
                return null;
            }

            $enriched = $this->normalizeTextAnnotationBackgroundForExport(
                $annotationAssets->enrichForPython($annotation)
            );
            if ($this->isUnchangedSyntheticSymbolAnnotation($enriched)) {
                return null;
            }

            return $enriched;
        }, $annotations)));

        // Suppress parent annotations when line-split children are present.
        // Child ids match "<parent_id>_lines-N-M"; writing both would double-render text.
        $allIds = array_flip(array_filter(array_map(
            static fn ($ann) => is_array($ann) ? (string) ($ann['id'] ?? '') : '',
            $prepared
        )));
        $parentsToSuppress = array_fill_keys(
            PdfAnnotationSuppression::suppressedIds(array_keys($allIds)),
            true
        );

        if (!empty($parentsToSuppress)) {
            $prepared = array_values(array_filter(
                $prepared,
                static fn ($ann) => !isset($parentsToSuppress[(string) ($ann['id'] ?? '')])
            ));
        }

        return $prepared;
    }

    private function isUnchangedSyntheticSymbolAnnotation(array $annotation): bool
    {
        if (strtolower(trim((string) ($annotation['type'] ?? ''))) !== 'text') {
            return false;
        }

        if (!$this->annotationFlagIsTruthy($annotation, 'syntheticSymbol')) {
            return false;
        }

        foreach (['pdfjsDeleted', 'userCreated', 'userAuthored', 'styleDirty', 'userForcedRichText', 'movedTextOverlay'] as $flag) {
            if ($this->annotationFlagIsTruthy($annotation, $flag)) {
                return false;
            }
        }

        $text = trim((string) ($annotation['text'] ?? ''));
        if ($text === '') {
            return false;
        }

        $original = trim((string) ($annotation['originalText'] ?? $annotation['pdfjsSourceText'] ?? ''));
        if ($original !== '' && $original !== $text) {
            return false;
        }

        return in_array($text, ['•', '●', '·', '∙', '◦', '▪', '▫'], true);
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

    private function resolveRedrawExtractionData(
        Document $document,
        ?string $userEmail = null,
        ?string $sessionId = null
    ): ?array {
        if ($this->usesAnnotationBaseMode()) {
            $annotationBaseData = $this->buildAnnotationBaseExtractionData($document);
            if (!empty($annotationBaseData)) {
                return $annotationBaseData;
            }
        }

        $extractionRow = $this->findLatestFitzExtraction($document->id, $userEmail, $sessionId);
        if (!$extractionRow || !isset($extractionRow->extraction_data)) {
            return null;
        }

        $rawExtractionData = is_string($extractionRow->extraction_data)
            ? json_decode($extractionRow->extraction_data, true)
            : $extractionRow->extraction_data;

        return is_array($rawExtractionData) ? $rawExtractionData : null;
    }

    private function resolveRedrawExtractionJson(
        Document $document,
        ?string $userEmail = null,
        ?string $sessionId = null
    ): ?string {
        $extractionData = $this->resolveRedrawExtractionData($document, $userEmail, $sessionId);
        if (!is_array($extractionData)) {
            return null;
        }

        $json = json_encode($extractionData, JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : null;
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
            str_contains($lower, 'montserrat') => 'Montserrat',
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

        $shouldSplitCluster = static function (array $previousEntry, array $nextEntry) use ($isFooterLine, $isNumberedLine, $normalizeLineText): bool {
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
            $dramaticIndentSplitThreshold = max(24, min($prevHeight, $nextHeight) * 1.2);
            if (
                preg_match('/[:)]\s*$/', $normalizeLineText($previousEntry)) === 1
                && ($nextLeft - $prevLeft) > max(14, min($prevHeight, $nextHeight) * 0.75)
            ) {
                return true;
            }

            if (($nextLeft - $prevLeft) > $dramaticIndentSplitThreshold) {
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
        $sourceSpans = array_values(array_filter(
            is_array($block['spans'] ?? null) ? $block['spans'] : [],
            function ($span) use ($normalizedLineBBoxes) {
                if (!is_array($span)) {
                    return false;
                }

                $spanBBox = is_array($span['bbox'] ?? null) ? array_slice($span['bbox'], 0, 4) : null;
                if (!is_array($spanBBox) || count($spanBBox) < 4) {
                    return false;
                }

                if (empty($normalizedLineBBoxes)) {
                    return true;
                }

                $spanRect = array_map('floatval', $spanBBox);
                foreach ($normalizedLineBBoxes as $lineBBox) {
                    if (!is_array($lineBBox) || count($lineBBox) < 4) {
                        continue;
                    }

                    if ($this->annotationBaseRectsIntersect(
                        array_map('floatval', array_slice($lineBBox, 0, 4)),
                        $spanRect,
                        0.25
                    )) {
                        return true;
                    }
                }

                return false;
            }
        ));

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
            'sourceSpans' => $sourceSpans,
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

        if (!config('pdf_editor.line_grouping_editor_mode', false)) {
            return [$baseAnnotation];
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

            $blocks = $this->mergeContainedStandaloneAnnotationBaseBlocks(
                is_array($pageData['blocks'] ?? null) ? $pageData['blocks'] : []
            );
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

    private function buildPromotedStateGroupsFromAnnotations(array $annotations): array
    {
        $groups = [];

        foreach ($annotations as $annotation) {
            if (!is_array($annotation) || empty($annotation['promotedFromExtraction'])) {
                continue;
            }

            $sourceKey = trim((string) ($annotation['promotedSourceKey'] ?? ''));
            if ($sourceKey === '') {
                continue;
            }

            $sourceDetails = $this->parsePromotedSourceKeyDetails($sourceKey);
            $rootSourceKey = trim((string) ($sourceDetails['root_source_key'] ?? ''));
            if ($rootSourceKey === '') {
                $rootSourceKey = $this->normalizePromotedSourceRootKey($sourceKey);
            }

            $pageNumber = isset($sourceDetails['page_number']) && $sourceDetails['page_number'] !== null
                ? (int) $sourceDetails['page_number']
                : ((isset($annotation['pageIndex']) && is_numeric($annotation['pageIndex']))
                    ? ((int) $annotation['pageIndex'] + 1)
                    : 1);

            if (!isset($groups[$rootSourceKey])) {
                $groups[$rootSourceKey] = [
                    'group_key' => $rootSourceKey,
                    'root_source_key' => $rootSourceKey,
                    'page_number' => $pageNumber - 1,
                    'group_type' => 'promoted_text',
                    'group_bbox' => null,
                    'annotation_ids' => [],
                    'annotation_source_keys' => [],
                    'group_data' => [
                        'page_number' => $pageNumber,
                        'page_width' => null,
                        'page_height' => isset($annotation['sourcePageHeight']) && is_numeric($annotation['sourcePageHeight'])
                            ? (float) $annotation['sourcePageHeight']
                            : null,
                        'source_text_lines' => [],
                        'source_line_bboxes' => [],
                        'source_spans' => [],
                        'annotations' => [],
                    ],
                    'state' => 'extracted',
                ];
            }

            $group = &$groups[$rootSourceKey];
            $annotationId = trim((string) ($annotation['id'] ?? ''));
            if ($annotationId !== '') {
                $group['annotation_ids'][] = $annotationId;
            }
            $group['annotation_source_keys'][] = $sourceKey;

            $lineBBoxes = array_values(array_filter(
                is_array($annotation['sourceLineBBoxes'] ?? null) ? $annotation['sourceLineBBoxes'] : [],
                static fn ($bbox) => is_array($bbox) && count($bbox) >= 4
            ));
            $textLines = array_values(array_map(
                fn ($line) => $this->sanitizePromotedExtractionLineForMaterialization($line),
                is_array($annotation['sourceTextLines'] ?? null) ? $annotation['sourceTextLines'] : []
            ));

            if (
                empty($lineBBoxes)
                && isset($annotation['sourceBlockLeft'], $annotation['sourceBlockTop'], $annotation['sourceBlockWidth'], $annotation['sourceBlockHeight'])
            ) {
                $left = (float) $annotation['sourceBlockLeft'];
                $top = (float) $annotation['sourceBlockTop'];
                $width = (float) $annotation['sourceBlockWidth'];
                $height = (float) $annotation['sourceBlockHeight'];
                if ($width > 0 && $height > 0) {
                    $lineBBoxes[] = [$left, $top, $left + $width, $top + $height];
                }
            }

            $group['group_data']['source_text_lines'] = array_merge($group['group_data']['source_text_lines'], $textLines);
            $group['group_data']['source_line_bboxes'] = array_merge($group['group_data']['source_line_bboxes'], $lineBBoxes);
            $group['group_data']['source_spans'] = array_merge(
                $group['group_data']['source_spans'],
                is_array($annotation['sourceSpans'] ?? null) ? array_values($annotation['sourceSpans']) : []
            );
            $group['group_data']['annotations'][] = [
                'id' => $annotationId,
                'source_key' => $sourceKey,
                'line_start' => $sourceDetails['line_start'],
                'line_end' => $sourceDetails['line_end'],
                'font_size' => isset($annotation['fontSize']) && is_numeric($annotation['fontSize']) ? (float) $annotation['fontSize'] : null,
                'font_family' => (string) ($annotation['fontFamily'] ?? ''),
                'font_source_name' => (string) ($annotation['fontSourceName'] ?? ''),
                'font_weight' => (string) ($annotation['fontWeight'] ?? ''),
                'font_style' => (string) ($annotation['fontStyle'] ?? ''),
                'underline' => (bool) ($annotation['underline'] ?? false),
                'text_color' => (string) ($annotation['textColor'] ?? '#000000'),
                'line_height' => isset($annotation['lineHeight']) && is_numeric($annotation['lineHeight']) ? (float) $annotation['lineHeight'] : null,
                'source_block_left' => isset($annotation['sourceBlockLeft']) && is_numeric($annotation['sourceBlockLeft']) ? (float) $annotation['sourceBlockLeft'] : null,
                'source_block_top' => isset($annotation['sourceBlockTop']) && is_numeric($annotation['sourceBlockTop']) ? (float) $annotation['sourceBlockTop'] : null,
                'source_block_width' => isset($annotation['sourceBlockWidth']) && is_numeric($annotation['sourceBlockWidth']) ? (float) $annotation['sourceBlockWidth'] : null,
                'source_block_height' => isset($annotation['sourceBlockHeight']) && is_numeric($annotation['sourceBlockHeight']) ? (float) $annotation['sourceBlockHeight'] : null,
            ];
            unset($group);
        }

        foreach ($groups as &$group) {
            usort($group['group_data']['annotations'], static function (array $left, array $right): int {
                $leftStart = $left['line_start'] ?? PHP_INT_MAX;
                $rightStart = $right['line_start'] ?? PHP_INT_MAX;
                if ($leftStart !== $rightStart) {
                    return $leftStart <=> $rightStart;
                }

                return strcmp((string) ($left['source_key'] ?? ''), (string) ($right['source_key'] ?? ''));
            });

            $orderedSourceKeys = array_map(
                static fn (array $entry): string => (string) ($entry['source_key'] ?? ''),
                $group['group_data']['annotations']
            );
            $group['annotation_source_keys'] = array_values(array_unique(array_filter($orderedSourceKeys)));

            $group['annotation_ids'] = array_values(array_unique(array_filter(
                $group['annotation_ids'],
                static fn ($value) => is_string($value) && trim($value) !== ''
            )));

            $lineBBoxes = array_values(array_filter(
                $group['group_data']['source_line_bboxes'],
                static fn ($bbox) => is_array($bbox) && count($bbox) >= 4
            ));

            if (!empty($lineBBoxes)) {
                $left = min(array_map(static fn (array $bbox): float => (float) $bbox[0], $lineBBoxes));
                $top = min(array_map(static fn (array $bbox): float => (float) $bbox[1], $lineBBoxes));
                $right = max(array_map(static fn (array $bbox): float => (float) $bbox[2], $lineBBoxes));
                $bottom = max(array_map(static fn (array $bbox): float => (float) $bbox[3], $lineBBoxes));
                $group['group_bbox'] = [$left, $top, $right, $bottom];
            } else {
                $firstAnnotation = $group['group_data']['annotations'][0] ?? [];
                $left = isset($firstAnnotation['source_block_left']) ? (float) $firstAnnotation['source_block_left'] : 0.0;
                $top = isset($firstAnnotation['source_block_top']) ? (float) $firstAnnotation['source_block_top'] : 0.0;
                $width = isset($firstAnnotation['source_block_width']) ? (float) $firstAnnotation['source_block_width'] : 0.0;
                $height = isset($firstAnnotation['source_block_height']) ? (float) $firstAnnotation['source_block_height'] : 0.0;
                if ($width > 0 && $height > 0) {
                    $group['group_bbox'] = [$left, $top, $left + $width, $top + $height];
                }
            }

            $group['group_data']['annotation_count'] = count($group['annotation_source_keys']);
            $group['group_data']['is_grouped'] = count($group['annotation_source_keys']) > 1;
        }
        unset($group);

        return array_values($groups);
    }

    private function mergeContainedStandaloneAnnotationBaseBlocks(array $blocks): array
    {
        if (count($blocks) < 2) {
            return $blocks;
        }

        $mergedBlocks = array_values($blocks);
        $removedIndices = [];

        foreach ($mergedBlocks as $childIndex => $childBlock) {
            if (isset($removedIndices[$childIndex])) {
                continue;
            }

            $childLineBBoxes = array_values(array_filter(
                is_array($childBlock['line_bboxes'] ?? null) ? $childBlock['line_bboxes'] : [],
                static fn ($bbox) => is_array($bbox) && count($bbox) >= 4
            ));
            $childTextLines = is_array($childBlock['text_lines'] ?? null) ? array_values($childBlock['text_lines']) : [];
            $childText = trim((string) ($childTextLines[0] ?? $childBlock['text'] ?? ''));
            $childBBox = $this->normalizeAnnotationBaseBlockBBox($childBlock);

            if (count($childLineBBoxes) !== 1 || $childText === '' || !$childBBox) {
                continue;
            }

            $bestParentIndex = null;
            $bestBlankLineIndex = null;
            $bestParentArea = null;

            foreach ($mergedBlocks as $parentIndex => $parentBlock) {
                if ($parentIndex === $childIndex || isset($removedIndices[$parentIndex])) {
                    continue;
                }

                $parentBBox = $this->normalizeAnnotationBaseBlockBBox($parentBlock);
                if (
                    !$parentBBox
                    || !$this->annotationBaseRectContains($parentBBox, $childBBox, 0.5)
                    || !$this->annotationBaseBlocksAreMergeCompatible($parentBlock, $childBlock)
                ) {
                    continue;
                }

                $parentLineBBoxes = array_values(array_filter(
                    is_array($parentBlock['line_bboxes'] ?? null) ? $parentBlock['line_bboxes'] : [],
                    static fn ($bbox) => is_array($bbox) && count($bbox) >= 4
                ));
                $parentTextLines = is_array($parentBlock['text_lines'] ?? null) ? array_values($parentBlock['text_lines']) : [];
                if (count($parentLineBBoxes) < 2 || count($parentTextLines) !== count($parentLineBBoxes)) {
                    continue;
                }

                foreach ($parentLineBBoxes as $lineIndex => $lineBBox) {
                    if (
                        trim((string) ($parentTextLines[$lineIndex] ?? '')) !== ''
                        || !$this->annotationBaseNestedBlockFitsBlankLineSlot(
                            array_map('floatval', array_slice($lineBBox, 0, 4)),
                            array_map('floatval', array_slice($childLineBBoxes[0], 0, 4))
                        )
                    ) {
                        continue;
                    }

                    $parentArea = max(1.0, ($parentBBox[2] - $parentBBox[0]) * ($parentBBox[3] - $parentBBox[1]));
                    if ($bestParentArea === null || $parentArea < $bestParentArea) {
                        $bestParentIndex = $parentIndex;
                        $bestBlankLineIndex = $lineIndex;
                        $bestParentArea = $parentArea;
                    }
                }
            }

            if ($bestParentIndex === null || $bestBlankLineIndex === null) {
                continue;
            }

            $parentBlock = $mergedBlocks[$bestParentIndex];
            $parentTextLines = array_values($parentBlock['text_lines'] ?? []);
            $parentLineBBoxes = array_values($parentBlock['line_bboxes'] ?? []);
            $parentTextLines[$bestBlankLineIndex] = $childText;
            $parentLineBBoxes[$bestBlankLineIndex] = array_map('floatval', array_slice($childLineBBoxes[0], 0, 4));

            $allLineBBoxes = array_values(array_filter(
                $parentLineBBoxes,
                static fn ($bbox) => is_array($bbox) && count($bbox) >= 4
            ));
            if (empty($allLineBBoxes)) {
                continue;
            }

            $left = min(array_map(static fn (array $bbox): float => (float) $bbox[0], $allLineBBoxes));
            $top = min(array_map(static fn (array $bbox): float => (float) $bbox[1], $allLineBBoxes));
            $right = max(array_map(static fn (array $bbox): float => (float) $bbox[2], $allLineBBoxes));
            $bottom = max(array_map(static fn (array $bbox): float => (float) $bbox[3], $allLineBBoxes));

            $mergedSpans = array_merge(
                is_array($parentBlock['spans'] ?? null) ? array_values($parentBlock['spans']) : [],
                is_array($childBlock['spans'] ?? null) ? array_values($childBlock['spans']) : []
            );
            usort($mergedSpans, static function (array $leftSpan, array $rightSpan): int {
                $leftBBox = is_array($leftSpan['bbox'] ?? null) ? array_slice($leftSpan['bbox'], 0, 4) : [0, 0, 0, 0];
                $rightBBox = is_array($rightSpan['bbox'] ?? null) ? array_slice($rightSpan['bbox'], 0, 4) : [0, 0, 0, 0];
                $leftTop = (float) ($leftBBox[1] ?? 0);
                $rightTop = (float) ($rightBBox[1] ?? 0);
                if (abs($leftTop - $rightTop) > 0.25) {
                    return $leftTop <=> $rightTop;
                }

                return ((float) ($leftBBox[0] ?? 0)) <=> ((float) ($rightBBox[0] ?? 0));
            });

            $parentBlock['text_lines'] = $parentTextLines;
            $parentBlock['text'] = implode("\n", $parentTextLines);
            $parentBlock['line_bboxes'] = $parentLineBBoxes;
            $parentBlock['left'] = $left;
            $parentBlock['top'] = $top;
            $parentBlock['width'] = max(0.0, $right - $left);
            $parentBlock['height'] = max(0.0, $bottom - $top);
            $parentBlock['bbox'] = [$left, $top, $right, $bottom];
            $parentBlock['line_count'] = count($parentTextLines);
            $parentBlock['spans'] = $mergedSpans;
            $mergedBlocks[$bestParentIndex] = $parentBlock;
            $removedIndices[$childIndex] = true;
        }

        $result = [];
        foreach ($mergedBlocks as $index => $block) {
            if (!isset($removedIndices[$index])) {
                $result[] = $block;
            }
        }

        return $result;
    }

    private function syncMaterializedPdfGroups(
        Document $document,
        array $groups,
        ?int $pdfExtractionFitzId = null
    ): int
    {
        $sessionId = $this->extractedPdfStateSessionId($document);
        $ownership = $this->resolvePdfStateOwnership($document);
        $groupKeys = array_values(array_unique(array_filter(array_map(
            static fn (array $group) => is_string($group['group_key'] ?? null)
                ? trim((string) $group['group_key'])
                : '',
            $groups
        ))));
        $syncedCount = 0;

        DB::transaction(function () use ($document, $sessionId, $ownership, $groups, $groupKeys, $pdfExtractionFitzId, &$syncedCount) {
            $staleOwnershipRowsQuery = PdfGroup::query()
                ->where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->where('state', 'extracted');

            if (($ownership['user_id'] ?? null) !== null) {
                $userId = (int) $ownership['user_id'];
                $staleOwnershipRowsQuery->where(function ($query) use ($userId) {
                    $query->whereNull('user_id')
                        ->orWhere('user_id', '!=', $userId)
                        ->orWhereNotNull('admin_id');
                });
            } elseif (($ownership['admin_id'] ?? null) !== null) {
                $adminId = (int) $ownership['admin_id'];
                $staleOwnershipRowsQuery->where(function ($query) use ($adminId) {
                    $query->whereNull('admin_id')
                        ->orWhere('admin_id', '!=', $adminId)
                        ->orWhereNotNull('user_id');
                });
            } else {
                $staleOwnershipRowsQuery->where(function ($query) {
                    $query->whereNotNull('user_id')
                        ->orWhereNotNull('admin_id');
                });
            }

            $staleOwnershipRowsQuery->delete();

            $existingRowsQuery = PdfGroup::query()
                ->where('document_id', $document->id)
                ->where('state', 'extracted');
            $this->applyPdfGroupOwnershipScope($existingRowsQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
            $existingRows = $existingRowsQuery
                ->get()
                ->keyBy(static fn (PdfGroup $record) => trim((string) $record->group_key));

            $seenGroupKeys = [];
            foreach ($groups as $group) {
                $groupKey = trim((string) ($group['group_key'] ?? ''));
                if ($groupKey === '') {
                    continue;
                }

                $pageNumber = isset($group['page_number']) && is_numeric($group['page_number'])
                    ? (int) $group['page_number']
                    : null;

                $payload = [
                    'pdf_extraction_fitz_id' => $pdfExtractionFitzId,
                    'page_number' => $pageNumber,
                    'group_key' => $groupKey,
                    'root_source_key' => trim((string) ($group['root_source_key'] ?? $groupKey)),
                    'group_type' => trim((string) ($group['group_type'] ?? 'promoted_text')) ?: 'promoted_text',
                    'group_bbox' => is_array($group['group_bbox'] ?? null) ? $group['group_bbox'] : null,
                    'annotation_ids' => is_array($group['annotation_ids'] ?? null) ? array_values($group['annotation_ids']) : [],
                    'annotation_source_keys' => is_array($group['annotation_source_keys'] ?? null) ? array_values($group['annotation_source_keys']) : [],
                    'group_data' => is_array($group['group_data'] ?? null) ? $group['group_data'] : [],
                    'state' => 'extracted',
                    'session_id' => $sessionId,
                    'user_id' => $ownership['user_id'],
                    'admin_id' => $ownership['admin_id'],
                    'user_email' => ($ownership['user_id'] !== null || $ownership['admin_id'] !== null)
                        ? null
                        : $this->resolveEditorEmail(),
                ];

                $existing = $existingRows->get($groupKey);
                if ($existing) {
                    $existing->update($payload);
                } else {
                    PdfGroup::create(array_merge([
                        'document_id' => $document->id,
                    ], $payload));
                }

                $seenGroupKeys[$groupKey] = true;
                $syncedCount++;
            }

            $staleIds = [];
            foreach ($existingRows as $existingGroupKey => $record) {
                if ($existingGroupKey === '' || isset($seenGroupKeys[$existingGroupKey])) {
                    continue;
                }
                $staleIds[] = $record->id;
            }

            if (!empty($staleIds)) {
                $staleRowsQuery = PdfGroup::query()
                    ->where('document_id', $document->id)
                    ->whereIn('id', $staleIds);
                $this->applyPdfGroupOwnershipScope($staleRowsQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
                $staleRowsQuery->delete();
            }

            if (empty($groupKeys)) {
                $clearRowsQuery = PdfGroup::query()
                    ->where('document_id', $document->id)
                    ->where('state', 'extracted');
                $this->applyPdfGroupOwnershipScope($clearRowsQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
                $clearRowsQuery->delete();
            }
        });

        return $syncedCount;
    }

    private function buildAnnotationBaseExtractionData(Document $document): array
    {
        $sessionId = $this->extractedPdfStateSessionId($document);
        $ownership = $this->resolvePdfStateOwnership($document);
        $rawPageDimensions = [];

        $stateRowsQuery = PdfState::query()
            ->where('document_id', $document->id)
            ->where('state', 'extracted');
        $this->applyPdfStateOwnershipScope($stateRowsQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
        $stateRows = $stateRowsQuery
            ->orderBy('page_number')
            ->orderBy('id')
            ->get();

        $groupRowsQuery = PdfGroup::query()
            ->where('document_id', $document->id)
            ->where('state', 'extracted');
        $this->applyPdfGroupOwnershipScope($groupRowsQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
        $groupRows = $groupRowsQuery
            ->orderBy('page_number')
            ->orderBy('id')
            ->get();

        $snapshotId = $this->findPinnedFitzExtractionSnapshotId($document, $stateRows, $groupRows);
        if ($snapshotId !== null && !$this->fitzExtractionNormalizer()->hasNormalizedSnapshot($snapshotId)) {
            $snapshot = $document->pdfExtractionsFitz()
                ->where('id', $snapshotId)
                ->first();
            if ($snapshot) {
                $this->normalizeFitzExtractionSnapshot($snapshot);
            }
        }

        if ($snapshotId !== null) {
            foreach ($this->fitzExtractionNormalizer()->buildPageDataForSnapshot($snapshotId) as $normalizedPage) {
                $pageNumber = is_numeric($normalizedPage['page_number'] ?? null)
                    ? (int) $normalizedPage['page_number']
                    : null;
                if (!$pageNumber) {
                    continue;
                }

                $rawPageDimensions[$pageNumber] = [
                    'width' => isset($normalizedPage['width']) && is_numeric($normalizedPage['width'])
                        ? (float) $normalizedPage['width']
                        : 0.0,
                    'height' => isset($normalizedPage['height']) && is_numeric($normalizedPage['height'])
                        ? (float) $normalizedPage['height']
                        : 0.0,
                ];
            }
        }

        $annotationsBySourceKey = [];
        foreach ($stateRows as $record) {
            $annotation = is_array($record->annotation_data) ? $record->annotation_data : [];
            if (empty($annotation['promotedFromExtraction'])) {
                continue;
            }

            $sourceKey = trim((string) ($annotation['promotedSourceKey'] ?? ''));
            if ($sourceKey === '') {
                continue;
            }

            $annotationsBySourceKey[$sourceKey] = $annotation;
        }

        $pages = [];
        $seenRootKeys = [];
        foreach ($groupRows as $groupRow) {
            $groupData = is_array($groupRow->group_data) ? $groupRow->group_data : [];
            $rootSourceKey = trim((string) ($groupRow->root_source_key ?: $groupRow->group_key));
            if ($rootSourceKey === '') {
                continue;
            }

            $pageNumber = is_numeric($groupData['page_number'] ?? null)
                ? (int) $groupData['page_number']
                : ((is_numeric($groupRow->page_number) ? ((int) $groupRow->page_number + 1) : 1));
            $pageIndex = max(1, $pageNumber);
            $rawPageWidth = (float) ($rawPageDimensions[$pageIndex]['width'] ?? 0.0);
            $rawPageHeight = (float) ($rawPageDimensions[$pageIndex]['height'] ?? 0.0);
            if (!isset($pages[$pageIndex])) {
                $pages[$pageIndex] = [
                    'page_number' => $pageIndex,
                    'width' => $rawPageWidth > 0
                        ? $rawPageWidth
                        : (isset($groupData['page_width']) && is_numeric($groupData['page_width'])
                            ? (float) $groupData['page_width']
                            : 0.0),
                    'height' => $rawPageHeight > 0
                        ? $rawPageHeight
                        : (isset($groupData['page_height']) && is_numeric($groupData['page_height'])
                            ? (float) $groupData['page_height']
                            : 0.0),
                    'blocks' => [],
                    'words' => [],
                ];
            } else {
                if ($rawPageWidth > 0) {
                    $pages[$pageIndex]['width'] = max((float) $pages[$pageIndex]['width'], $rawPageWidth);
                } elseif (isset($groupData['page_width']) && is_numeric($groupData['page_width'])) {
                    $pages[$pageIndex]['width'] = max((float) $pages[$pageIndex]['width'], (float) $groupData['page_width']);
                }
                if ($rawPageHeight > 0) {
                    $pages[$pageIndex]['height'] = max((float) $pages[$pageIndex]['height'], $rawPageHeight);
                } elseif (isset($groupData['page_height']) && is_numeric($groupData['page_height'])) {
                    $pages[$pageIndex]['height'] = max((float) $pages[$pageIndex]['height'], (float) $groupData['page_height']);
                }
            }

            $orderedSourceKeys = array_values(array_filter(
                is_array($groupRow->annotation_source_keys) ? $groupRow->annotation_source_keys : [],
                static fn ($value) => is_string($value) && trim($value) !== ''
            ));

            $memberAnnotations = [];
            foreach ($orderedSourceKeys as $sourceKey) {
                $sourceKey = trim((string) $sourceKey);
                if ($sourceKey !== '' && isset($annotationsBySourceKey[$sourceKey])) {
                    $memberAnnotations[] = $annotationsBySourceKey[$sourceKey];
                }
            }

            if (empty($memberAnnotations)) {
                continue;
            }

            $firstAnnotation = $memberAnnotations[0];
            $blockNum = (int) (($this->parsePromotedSourceKeyDetails($rootSourceKey)['block_num']) ?? 0);
            $lineBBoxes = [];
            $textLines = [];
            $sourceSpans = [];

            foreach ($memberAnnotations as $annotation) {
                $memberTextLines = array_values(array_map(
                    fn ($line) => $this->sanitizePromotedExtractionLineForMaterialization($line),
                    is_array($annotation['sourceTextLines'] ?? null) ? $annotation['sourceTextLines'] : []
                ));
                $memberLineBBoxes = array_values(array_filter(
                    is_array($annotation['sourceLineBBoxes'] ?? null) ? $annotation['sourceLineBBoxes'] : [],
                    static fn ($bbox) => is_array($bbox) && count($bbox) >= 4
                ));

                // Blank visual lines are meaningful placeholders for nested
                // extraction blocks. Preserve them whenever line text and
                // bboxes are aligned so the contained-block merge can replace
                // the correct slot instead of splitting the parent apart.
                if (count($memberTextLines) === count($memberLineBBoxes)) {
                    $textLines = array_merge($textLines, $memberTextLines);
                    $lineBBoxes = array_merge($lineBBoxes, $memberLineBBoxes);
                } else {
                    $textLines = array_merge($textLines, array_values(array_filter(
                        $memberTextLines,
                        static fn ($line) => $line !== ''
                    )));
                    $lineBBoxes = array_merge($lineBBoxes, $memberLineBBoxes);
                }
                $sourceSpans = array_merge(
                    $sourceSpans,
                    is_array($annotation['sourceSpans'] ?? null) ? array_values($annotation['sourceSpans']) : []
                );
            }

            $groupBbox = is_array($groupRow->group_bbox) && count($groupRow->group_bbox) >= 4
                ? array_values(array_map('floatval', array_slice($groupRow->group_bbox, 0, 4)))
                : null;

            if ($groupBbox === null && !empty($lineBBoxes)) {
                $groupBbox = [
                    min(array_map(static fn (array $bbox): float => (float) $bbox[0], $lineBBoxes)),
                    min(array_map(static fn (array $bbox): float => (float) $bbox[1], $lineBBoxes)),
                    max(array_map(static fn (array $bbox): float => (float) $bbox[2], $lineBBoxes)),
                    max(array_map(static fn (array $bbox): float => (float) $bbox[3], $lineBBoxes)),
                ];
            }

            if ($groupBbox === null) {
                continue;
            }

            [$left, $top, $right, $bottom] = $groupBbox;
            $pages[$pageIndex]['blocks'][] = [
                'block_num' => $blockNum,
                'text' => implode("\n", $textLines),
                'text_lines' => $textLines,
                'line_bboxes' => array_values(array_map(
                    static fn (array $bbox): array => array_map(static fn ($value): float => (float) $value, array_slice($bbox, 0, 4)),
                    $lineBBoxes
                )),
                'left' => $left,
                'top' => $top,
                'width' => max(0, $right - $left),
                'height' => max(0, $bottom - $top),
                'bbox' => [$left, $top, $right, $bottom],
                'font_size' => (float) ($firstAnnotation['fontSize'] ?? 12),
                'line_height' => isset($firstAnnotation['lineHeight']) && is_numeric($firstAnnotation['lineHeight'])
                    ? (float) $firstAnnotation['lineHeight']
                    : null,
                'avg_line_height' => isset($firstAnnotation['lineHeight']) && is_numeric($firstAnnotation['lineHeight'])
                    ? (float) $firstAnnotation['lineHeight']
                    : null,
                'font' => (string) ($firstAnnotation['fontSourceName'] ?? $firstAnnotation['fontFamily'] ?? 'Helvetica'),
                'font_weight' => (string) ($firstAnnotation['fontWeight'] ?? '400'),
                'italic' => (($firstAnnotation['fontStyle'] ?? 'normal') === 'italic'),
                'underline' => (bool) ($firstAnnotation['underline'] ?? false),
                'hex_color' => (string) ($firstAnnotation['textColor'] ?? '#000000'),
                'spans' => $sourceSpans,
            ];
            $seenRootKeys[$rootSourceKey] = true;
        }

        foreach ($annotationsBySourceKey as $sourceKey => $annotation) {
            $rootSourceKey = $this->normalizePromotedSourceRootKey($sourceKey);
            if (isset($seenRootKeys[$rootSourceKey])) {
                continue;
            }

            $sourceDetails = $this->parsePromotedSourceKeyDetails($sourceKey);
            $pageNumber = (int) (($sourceDetails['page_number']) ?? ((int) ($annotation['pageIndex'] ?? 0) + 1));
            $pageIndex = max(1, $pageNumber);
            $rawPageWidth = (float) ($rawPageDimensions[$pageIndex]['width'] ?? 0.0);
            $rawPageHeight = (float) ($rawPageDimensions[$pageIndex]['height'] ?? 0.0);
            if (!isset($pages[$pageIndex])) {
                $pages[$pageIndex] = [
                    'page_number' => $pageIndex,
                    'width' => $rawPageWidth,
                    'height' => $rawPageHeight > 0
                        ? $rawPageHeight
                        : (isset($annotation['sourcePageHeight']) && is_numeric($annotation['sourcePageHeight'])
                            ? (float) $annotation['sourcePageHeight']
                            : 0.0),
                    'blocks' => [],
                    'words' => [],
                ];
            } else {
                if ($rawPageWidth > 0) {
                    $pages[$pageIndex]['width'] = max((float) $pages[$pageIndex]['width'], $rawPageWidth);
                }
                if ($rawPageHeight > 0) {
                    $pages[$pageIndex]['height'] = max((float) $pages[$pageIndex]['height'], $rawPageHeight);
                }
            }

            $lineBBoxes = array_values(array_filter(
                is_array($annotation['sourceLineBBoxes'] ?? null) ? $annotation['sourceLineBBoxes'] : [],
                static fn ($bbox) => is_array($bbox) && count($bbox) >= 4
            ));
            if (empty($lineBBoxes) && isset($annotation['sourceBlockLeft'], $annotation['sourceBlockTop'], $annotation['sourceBlockWidth'], $annotation['sourceBlockHeight'])) {
                $left = (float) $annotation['sourceBlockLeft'];
                $top = (float) $annotation['sourceBlockTop'];
                $width = (float) $annotation['sourceBlockWidth'];
                $height = (float) $annotation['sourceBlockHeight'];
                if ($width > 0 && $height > 0) {
                    $lineBBoxes[] = [$left, $top, $left + $width, $top + $height];
                }
            }
            if (empty($lineBBoxes)) {
                continue;
            }

            $left = min(array_map(static fn (array $bbox): float => (float) $bbox[0], $lineBBoxes));
            $top = min(array_map(static fn (array $bbox): float => (float) $bbox[1], $lineBBoxes));
            $right = max(array_map(static fn (array $bbox): float => (float) $bbox[2], $lineBBoxes));
            $bottom = max(array_map(static fn (array $bbox): float => (float) $bbox[3], $lineBBoxes));

            $pages[$pageIndex]['blocks'][] = [
                'block_num' => (int) (($sourceDetails['block_num']) ?? 0),
                'text' => implode("\n", array_values(array_filter(array_map(
                    fn ($line) => $this->sanitizePromotedExtractionLineForMaterialization($line),
                    is_array($annotation['sourceTextLines'] ?? null) ? $annotation['sourceTextLines'] : []
                ), static fn ($line) => $line !== ''))),
                'text_lines' => array_values(array_filter(array_map(
                    fn ($line) => $this->sanitizePromotedExtractionLineForMaterialization($line),
                    is_array($annotation['sourceTextLines'] ?? null) ? $annotation['sourceTextLines'] : []
                ), static fn ($line) => $line !== '')),
                'line_bboxes' => array_values(array_map(
                    static fn (array $bbox): array => array_map(static fn ($value): float => (float) $value, array_slice($bbox, 0, 4)),
                    $lineBBoxes
                )),
                'left' => $left,
                'top' => $top,
                'width' => max(0, $right - $left),
                'height' => max(0, $bottom - $top),
                'bbox' => [$left, $top, $right, $bottom],
                'font_size' => (float) ($annotation['fontSize'] ?? 12),
                'line_height' => isset($annotation['lineHeight']) && is_numeric($annotation['lineHeight'])
                    ? (float) $annotation['lineHeight']
                    : null,
                'avg_line_height' => isset($annotation['lineHeight']) && is_numeric($annotation['lineHeight'])
                    ? (float) $annotation['lineHeight']
                    : null,
                'font' => (string) ($annotation['fontSourceName'] ?? $annotation['fontFamily'] ?? 'Helvetica'),
                'font_weight' => (string) ($annotation['fontWeight'] ?? '400'),
                'italic' => (($annotation['fontStyle'] ?? 'normal') === 'italic'),
                'underline' => (bool) ($annotation['underline'] ?? false),
                'hex_color' => (string) ($annotation['textColor'] ?? '#000000'),
                'spans' => is_array($annotation['sourceSpans'] ?? null) ? array_values($annotation['sourceSpans']) : [],
            ];
        }

        ksort($pages);
        foreach ($pages as &$page) {
            $page['blocks'] = $this->mergeContainedStandaloneAnnotationBaseBlocks(
                is_array($page['blocks'] ?? null) ? $page['blocks'] : []
            );
            $page['blocks'] = $this->splitNestedAnnotationBaseBlocks(
                is_array($page['blocks'] ?? null) ? $page['blocks'] : []
            );
            $page['blocks'] = $this->normalizeAnnotationBaseDotLeaderRows(
                is_array($page['blocks'] ?? null) ? $page['blocks'] : []
            );
            usort($page['blocks'], static function (array $left, array $right): int {
                $leftTop = (float) ($left['top'] ?? 0);
                $rightTop = (float) ($right['top'] ?? 0);
                if ($leftTop !== $rightTop) {
                    return $leftTop <=> $rightTop;
                }

                return ((int) ($left['block_num'] ?? 0)) <=> ((int) ($right['block_num'] ?? 0));
            });

            $pageNumber = (int) ($page['page_number'] ?? 0);
            $rawPageWidth = (float) ($rawPageDimensions[$pageNumber]['width'] ?? 0.0);
            $rawPageHeight = (float) ($rawPageDimensions[$pageNumber]['height'] ?? 0.0);

            if ($rawPageWidth > 0) {
                $page['width'] = $rawPageWidth;
            } elseif ((float) ($page['width'] ?? 0) <= 0) {
                $page['width'] = array_reduce($page['blocks'], static function (float $carry, array $block): float {
                    return max($carry, (float) (($block['left'] ?? 0) + ($block['width'] ?? 0)));
                }, 0.0);
            }
            if ($rawPageHeight > 0) {
                $page['height'] = $rawPageHeight;
            } elseif ((float) ($page['height'] ?? 0) <= 0) {
                $page['height'] = array_reduce($page['blocks'], static function (float $carry, array $block): float {
                    return max($carry, (float) (($block['top'] ?? 0) + ($block['height'] ?? 0)));
                }, 0.0);
            }
        }
        unset($page);

        return array_values($pages);
    }

    private function normalizeAnnotationBaseBlockBBox(array $block): ?array
    {
        $bbox = is_array($block['bbox'] ?? null) ? array_slice($block['bbox'], 0, 4) : null;
        if (is_array($bbox) && count($bbox) >= 4) {
            $normalized = array_map('floatval', $bbox);
            if (count(array_filter($normalized, 'is_finite')) === 4) {
                return $normalized;
            }
        }

        $left = isset($block['left']) && is_numeric($block['left']) ? (float) $block['left'] : null;
        $top = isset($block['top']) && is_numeric($block['top']) ? (float) $block['top'] : null;
        $width = isset($block['width']) && is_numeric($block['width']) ? (float) $block['width'] : null;
        $height = isset($block['height']) && is_numeric($block['height']) ? (float) $block['height'] : null;
        if ($left === null || $top === null || $width === null || $height === null) {
            return null;
        }

        return [$left, $top, $left + max(0.0, $width), $top + max(0.0, $height)];
    }

    private function annotationBaseRectsIntersect(array $rectA, array $rectB, float $padding = 0.0): bool
    {
        return !(
            ($rectA[2] + $padding) <= ($rectB[0] - $padding)
            || ($rectB[2] + $padding) <= ($rectA[0] - $padding)
            || ($rectA[3] + $padding) <= ($rectB[1] - $padding)
            || ($rectB[3] + $padding) <= ($rectA[1] - $padding)
        );
    }

    private function annotationBaseRectContains(array $outerRect, array $innerRect, float $padding = 0.0): bool
    {
        return ($innerRect[0] + $padding) >= ($outerRect[0] - $padding)
            && ($innerRect[1] + $padding) >= ($outerRect[1] - $padding)
            && ($innerRect[2] - $padding) <= ($outerRect[2] + $padding)
            && ($innerRect[3] - $padding) <= ($outerRect[3] + $padding);
    }

    private function annotationBaseBlocksAreMergeCompatible(array $parentBlock, array $childBlock): bool
    {
        $normalizeValue = static fn ($value): string => trim(strtolower((string) $value));

        $parentFont = $normalizeValue($parentBlock['font'] ?? $parentBlock['fontSourceName'] ?? '');
        $childFont = $normalizeValue($childBlock['font'] ?? $childBlock['fontSourceName'] ?? '');
        if ($parentFont !== '' && $childFont !== '' && $parentFont !== $childFont) {
            return false;
        }

        $parentWeight = $normalizeValue($parentBlock['font_weight'] ?? $parentBlock['fontWeight'] ?? '');
        $childWeight = $normalizeValue($childBlock['font_weight'] ?? $childBlock['fontWeight'] ?? '');
        if ($parentWeight !== '' && $childWeight !== '' && $parentWeight !== $childWeight) {
            return false;
        }

        $parentItalic = (bool) ($parentBlock['italic'] ?? false)
            || $normalizeValue($parentBlock['fontStyle'] ?? '') === 'italic';
        $childItalic = (bool) ($childBlock['italic'] ?? false)
            || $normalizeValue($childBlock['fontStyle'] ?? '') === 'italic';
        if ($parentItalic !== $childItalic) {
            return false;
        }

        $parentColor = $normalizeValue($parentBlock['hex_color'] ?? $parentBlock['textColor'] ?? '');
        $childColor = $normalizeValue($childBlock['hex_color'] ?? $childBlock['textColor'] ?? '');
        if ($parentColor !== '' && $childColor !== '' && $parentColor !== $childColor) {
            return false;
        }

        $parentFontSize = isset($parentBlock['font_size']) && is_numeric($parentBlock['font_size'])
            ? (float) $parentBlock['font_size']
            : (isset($parentBlock['fontSize']) && is_numeric($parentBlock['fontSize']) ? (float) $parentBlock['fontSize'] : null);
        $childFontSize = isset($childBlock['font_size']) && is_numeric($childBlock['font_size'])
            ? (float) $childBlock['font_size']
            : (isset($childBlock['fontSize']) && is_numeric($childBlock['fontSize']) ? (float) $childBlock['fontSize'] : null);

        return $parentFontSize === null
            || $childFontSize === null
            || abs($parentFontSize - $childFontSize) <= 0.5;
    }

    private function annotationBaseNestedBlockFitsBlankLineSlot(array $blankLineBBox, array $childLineBBox): bool
    {
        $blankWidth = max(0.0, (float) ($blankLineBBox[2] ?? 0) - (float) ($blankLineBBox[0] ?? 0));
        if ($blankWidth > 2.0 && !$this->annotationBaseRectsIntersect($blankLineBBox, $childLineBBox, 0.5)) {
            return false;
        }

        $blankTop = (float) ($blankLineBBox[1] ?? 0);
        $blankBottom = (float) ($blankLineBBox[3] ?? $blankTop);
        $childTop = (float) ($childLineBBox[1] ?? 0);
        $childBottom = (float) ($childLineBBox[3] ?? $childTop);
        $childCenter = ($childTop + $childBottom) / 2;

        return $childCenter >= ($blankTop - 2.0)
            && $childCenter <= ($blankBottom + 2.0);
    }

    private function splitNestedAnnotationBaseBlocks(array $blocks): array
    {
        if (count($blocks) < 2) {
            return $blocks;
        }

        $normalizedBlocks = array_values($blocks);
        $maxBlockNum = array_reduce($normalizedBlocks, static function (int $carry, array $block): int {
            return max($carry, (int) ($block['block_num'] ?? 0));
        }, 0);
        $nextSyntheticBlockNum = $maxBlockNum + 1;
        $result = [];

        foreach ($normalizedBlocks as $index => $block) {
            $lineBBoxes = array_values(array_filter(
                is_array($block['line_bboxes'] ?? null) ? $block['line_bboxes'] : [],
                static fn ($bbox) => is_array($bbox) && count($bbox) >= 4
            ));
            if (count($lineBBoxes) < 2) {
                $result[] = $block;
                continue;
            }

            $blockBBox = $this->normalizeAnnotationBaseBlockBBox($block);
            if (!$blockBBox) {
                $result[] = $block;
                continue;
            }

            $shouldSplit = false;
            foreach ($normalizedBlocks as $siblingIndex => $sibling) {
                if ($siblingIndex === $index) {
                    continue;
                }

                $siblingBBox = $this->normalizeAnnotationBaseBlockBBox($sibling);
                if (!$siblingBBox || !$this->annotationBaseRectsIntersect($blockBBox, $siblingBBox, 0.25)) {
                    continue;
                }

                $intersectsAnyLine = false;
                foreach ($lineBBoxes as $lineBBox) {
                    $normalizedLineBBox = array_map('floatval', array_slice($lineBBox, 0, 4));
                    if ($this->annotationBaseRectsIntersect($normalizedLineBBox, $siblingBBox, 0.25)) {
                        $intersectsAnyLine = true;
                        break;
                    }
                }

                if (!$intersectsAnyLine) {
                    $shouldSplit = true;
                    break;
                }
            }

            if (!$shouldSplit) {
                $result[] = $block;
                continue;
            }

            $textLines = array_values(array_filter(array_map(
                fn ($line) => $this->sanitizePromotedExtractionLineForMaterialization($line),
                is_array($block['text_lines'] ?? null) ? $block['text_lines'] : []
            ), static fn ($line) => $line !== ''));
            $sourceSpans = is_array($block['spans'] ?? null) ? array_values($block['spans']) : [];

            foreach ($lineBBoxes as $lineIndex => $lineBBox) {
                $normalizedLineBBox = array_map('floatval', array_slice($lineBBox, 0, 4));
                if (count($normalizedLineBBox) < 4) {
                    continue;
                }

                [$left, $top, $right, $bottom] = $normalizedLineBBox;
                $lineText = $textLines[$lineIndex] ?? trim((string) ($block['text'] ?? ''));
                $lineSpans = array_values(array_filter($sourceSpans, function ($span) use ($normalizedLineBBox) {
                    if (!is_array($span)) {
                        return false;
                    }
                    $spanBBox = is_array($span['bbox'] ?? null) ? array_slice($span['bbox'], 0, 4) : null;
                    if (!is_array($spanBBox) || count($spanBBox) < 4) {
                        return false;
                    }

                    return $this->annotationBaseRectsIntersect(
                        array_map('floatval', $normalizedLineBBox),
                        array_map('floatval', $spanBBox),
                        0.25
                    );
                }));

                $splitBlock = $block;
                $splitBlock['block_num'] = $lineIndex === 0
                    ? (int) ($block['block_num'] ?? 0)
                    : $nextSyntheticBlockNum++;
                $splitBlock['text'] = $lineText;
                $splitBlock['text_lines'] = [$lineText];
                $splitBlock['line_bboxes'] = [$normalizedLineBBox];
                $splitBlock['left'] = $left;
                $splitBlock['top'] = $top;
                $splitBlock['width'] = max(0.0, $right - $left);
                $splitBlock['height'] = max(0.0, $bottom - $top);
                $splitBlock['bbox'] = [$left, $top, $right, $bottom];
                $splitBlock['spans'] = !empty($lineSpans) ? $lineSpans : $sourceSpans;
                $result[] = $splitBlock;
            }
        }

        usort($result, static function (array $left, array $right): int {
            $leftTop = (float) ($left['top'] ?? 0);
            $rightTop = (float) ($right['top'] ?? 0);
            if ($leftTop !== $rightTop) {
                return $leftTop <=> $rightTop;
            }

            $leftValue = (float) ($left['left'] ?? 0);
            $rightValue = (float) ($right['left'] ?? 0);
            if ($leftValue !== $rightValue) {
                return $leftValue <=> $rightValue;
            }

            return ((int) ($left['block_num'] ?? 0)) <=> ((int) ($right['block_num'] ?? 0));
        });

        return $result;
    }

    private function normalizeAnnotationBaseDotLeaderRows(array $blocks): array
    {
        if (count($blocks) < 2) {
            return $blocks;
        }

        $originalBlocks = array_values($blocks);
        $spanEntries = [];
        $dotSpanCount = 0;
        $formCodeSpanCount = 0;
        $maxBlockNum = 0;

        foreach ($originalBlocks as $blockIndex => $block) {
            $maxBlockNum = max($maxBlockNum, (int) ($block['block_num'] ?? 0));
            $spans = is_array($block['spans'] ?? null) ? array_values($block['spans']) : [];
            if (empty($spans)) {
                continue;
            }

            foreach ($spans as $spanIndex => $span) {
                if (!is_array($span)) {
                    continue;
                }

                $bbox = is_array($span['bbox'] ?? null) ? array_slice($span['bbox'], 0, 4) : null;
                if (!is_array($bbox) || count($bbox) < 4) {
                    continue;
                }

                $origin = is_array($span['origin'] ?? null) ? $span['origin'] : [];
                $baseline = isset($origin[1]) && is_numeric($origin[1])
                    ? (float) $origin[1]
                    : (float) ($bbox[3] ?? 0);
                if (!is_finite($baseline)) {
                    continue;
                }

                $text = (string) ($span['render_text'] ?? $span['text'] ?? '');
                if (preg_match('/^\s*\.\s*$/', $text) === 1) {
                    $dotSpanCount++;
                }
                if (preg_match('/^\s*\.?\s*(?:W-[A-Z0-9-]+|[0-9]{4}\s+or\s+W-\d)/i', trim($text)) === 1) {
                    $formCodeSpanCount++;
                }

                $spanEntries[] = [
                    'block' => $block,
                    'block_index' => $blockIndex,
                    'span_index' => $spanIndex,
                    'span' => $span,
                    'bbox' => array_map('floatval', $bbox),
                    'baseline' => $baseline,
                    'x' => (float) ($bbox[0] ?? 0),
                ];
            }
        }

        if ($dotSpanCount < 8 || $formCodeSpanCount < 1 || count($spanEntries) < 3) {
            return $blocks;
        }

        usort($spanEntries, static function (array $left, array $right): int {
            if (abs($left['baseline'] - $right['baseline']) > 1.0) {
                return $left['baseline'] <=> $right['baseline'];
            }
            if (abs($left['x'] - $right['x']) > 0.25) {
                return $left['x'] <=> $right['x'];
            }
            return ((int) $left['span_index']) <=> ((int) $right['span_index']);
        });

        $rows = [];
        foreach ($spanEntries as $entry) {
            $lastIndex = count($rows) - 1;
            if ($lastIndex < 0 || abs($entry['baseline'] - $rows[$lastIndex]['baseline']) > 1.25) {
                $rows[] = [
                    'baseline' => $entry['baseline'],
                    'entries' => [$entry],
                ];
                continue;
            }

            $rows[$lastIndex]['entries'][] = $entry;
            $rows[$lastIndex]['baseline'] = (
                ($rows[$lastIndex]['baseline'] * (count($rows[$lastIndex]['entries']) - 1))
                + $entry['baseline']
            ) / count($rows[$lastIndex]['entries']);
        }

        $rebuiltBlocks = [];
        $touchedBlockIndexes = [];
        $syntheticBlockNum = $maxBlockNum + 1;
        foreach ($rows as $row) {
            $entries = $row['entries'];
            usort($entries, static function (array $left, array $right): int {
                if (abs($left['x'] - $right['x']) > 0.25) {
                    return $left['x'] <=> $right['x'];
                }
                return ((int) $left['span_index']) <=> ((int) $right['span_index']);
            });

            $rowDotCount = 0;
            $rowHasFormCode = false;
            foreach ($entries as $entry) {
                $text = trim((string) ($entry['span']['render_text'] ?? $entry['span']['text'] ?? ''));
                if ($text === '.') {
                    $rowDotCount++;
                }
                if (preg_match('/^\s*\.?\s*(?:W-[A-Z0-9-]+|[0-9]{4}\s+or\s+W-\d)/i', $text) === 1) {
                    $rowHasFormCode = true;
                }
            }
            if ($rowDotCount < 3 && !$rowHasFormCode) {
                continue;
            }

            $entries = $this->synthesizeMissingAnnotationBaseLeaderDots($entries, (float) $row['baseline']);
            $spans = array_values(array_map(static fn (array $entry): array => $entry['span'], $entries));
            if (empty($spans)) {
                continue;
            }

            $bboxes = array_values(array_filter(array_map(
                static fn (array $entry): ?array => is_array($entry['bbox'] ?? null) && count($entry['bbox']) >= 4
                    ? array_map('floatval', array_slice($entry['bbox'], 0, 4))
                    : null,
                $entries
            )));
            if (empty($bboxes)) {
                continue;
            }

            $left = min(array_map(static fn (array $bbox): float => (float) $bbox[0], $bboxes));
            $top = min(array_map(static fn (array $bbox): float => (float) $bbox[1], $bboxes));
            $right = max(array_map(static fn (array $bbox): float => (float) $bbox[2], $bboxes));
            $bottom = max(array_map(static fn (array $bbox): float => (float) $bbox[3], $bboxes));
            if (($right - $left) <= 1 || ($bottom - $top) <= 0) {
                continue;
            }

            $template = $entries[0]['block'];
            foreach ($entries as $entry) {
                $text = trim((string) ($entry['span']['render_text'] ?? $entry['span']['text'] ?? ''));
                if ($text !== '.' && $text !== '') {
                    $template = $entry['block'];
                    break;
                }
            }

            $lineText = $this->annotationBaseTextFromOrderedSpans($spans);
            if ($lineText === '') {
                continue;
            }

            $block = $template;
            $block['block_num'] = (int) ($template['block_num'] ?? 0);
            if (isset($rebuiltBlocks[$block['block_num']])) {
                $block['block_num'] = $syntheticBlockNum++;
            }
            $block['text'] = $lineText;
            $block['text_lines'] = [$lineText];
            $block['line_bboxes'] = [[$left, $top, $right, $bottom]];
            $block['left'] = $left;
            $block['top'] = $top;
            $block['width'] = max(0.0, $right - $left);
            $block['height'] = max(0.0, $bottom - $top);
            $block['bbox'] = [$left, $top, $right, $bottom];
            $block['line_count'] = 1;
            $block['spans'] = $spans;
            $rebuiltBlocks[$block['block_num']] = $block;
            foreach ($entries as $entry) {
                if (isset($entry['block_index'])) {
                    $touchedBlockIndexes[(int) $entry['block_index']] = true;
                }
            }
        }

        if (empty($rebuiltBlocks)) {
            return $blocks;
        }

        $keptBlocks = [];
        foreach ($originalBlocks as $blockIndex => $block) {
            if (!isset($touchedBlockIndexes[$blockIndex])) {
                $keptBlocks[] = $block;
            }
        }

        return array_values(array_merge($keptBlocks, array_values($rebuiltBlocks)));
    }

    private function annotationBaseTextFromOrderedSpans(array $spans): string
    {
        $parts = [];
        foreach ($spans as $span) {
            if (!is_array($span)) {
                continue;
            }
            $text = preg_replace('/[ \t]+/', ' ', (string) ($span['render_text'] ?? $span['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $parts[] = trim($text);
        }

        return trim(preg_replace('/[ \t]{2,}/', ' ', implode(' ', $parts)));
    }

    private function synthesizeMissingAnnotationBaseLeaderDots(array $entries, float $baseline): array
    {
        if (count($entries) < 2) {
            return $entries;
        }

        $rightCodeIndex = null;
        foreach ($entries as $index => $entry) {
            $text = trim((string) ($entry['span']['render_text'] ?? $entry['span']['text'] ?? ''));
            if (preg_match('/^\s*\.?\s*W-[A-Z0-9-]+/i', $text) === 1) {
                $rightCodeIndex = $index;
                break;
            }
        }

        if ($rightCodeIndex === null || $rightCodeIndex === 0) {
            return $entries;
        }

        $rightEntry = $entries[$rightCodeIndex];
        $rightBBox = array_map('floatval', array_slice($rightEntry['bbox'], 0, 4));
        $rightLeft = (float) ($rightBBox[0] ?? 0);
        $leftEntry = null;
        for ($index = $rightCodeIndex - 1; $index >= 0; $index--) {
            $text = trim((string) ($entries[$index]['span']['render_text'] ?? $entries[$index]['span']['text'] ?? ''));
            if ($text !== '.' && $text !== '') {
                $leftEntry = $entries[$index];
                break;
            }
        }

        if (!$leftEntry) {
            return $entries;
        }

        $leftBBox = array_map('floatval', array_slice($leftEntry['bbox'], 0, 4));
        $leftRight = (float) ($leftBBox[2] ?? 0);
        $gap = $rightLeft - $leftRight;
        if ($gap < 54.0) {
            return $entries;
        }

        $existingDots = 0;
        foreach ($entries as $entry) {
            $text = trim((string) ($entry['span']['render_text'] ?? $entry['span']['text'] ?? ''));
            $bbox = array_map('floatval', array_slice($entry['bbox'], 0, 4));
            $x = (float) ($bbox[0] ?? 0);
            if ($text === '.' && $x > $leftRight && $x < $rightLeft) {
                $existingDots++;
            }
        }

        if ($existingDots >= 3) {
            return $entries;
        }

        $templateSpan = $rightEntry['span'];
        $fontSize = isset($templateSpan['font_size']) && is_numeric($templateSpan['font_size'])
            ? (float) $templateSpan['font_size']
            : (isset($templateSpan['size']) && is_numeric($templateSpan['size']) ? (float) $templateSpan['size'] : 8.0);
        $dotWidth = max(1.2, min(3.0, $fontSize * 0.28));
        $dotHeight = max(0.75, min(2.0, $fontSize * 0.12));
        $step = 12.0;
        $syntheticEntries = [];
        for ($x = $leftRight + $step; $x < ($rightLeft - $step); $x += $step) {
            $span = $templateSpan;
            $span['text'] = '.';
            $span['render_text'] = '.';
            $span['bbox'] = [$x, $baseline - $dotHeight, $x + $dotWidth, $baseline];
            $span['origin'] = [$x, $baseline];
            $span['synthetic_leader_dot'] = true;
            $syntheticEntries[] = [
                'block' => $rightEntry['block'],
                'block_index' => $rightEntry['block_index'],
                'span_index' => -1,
                'span' => $span,
                'bbox' => $span['bbox'],
                'baseline' => $baseline,
                'x' => $x,
            ];
        }

        if (empty($syntheticEntries)) {
            return $entries;
        }

        $entries = array_merge($entries, $syntheticEntries);
        usort($entries, static function (array $left, array $right): int {
            if (abs($left['x'] - $right['x']) > 0.25) {
                return $left['x'] <=> $right['x'];
            }
            return ((int) $left['span_index']) <=> ((int) $right['span_index']);
        });

        return $entries;
    }

    private function materializeFitzExtractionToPdfState(Document $document, $extractionRow): int
    {
        if (!$extractionRow) {
            return 0;
        }

        $snapshotId = $this->normalizeFitzExtractionSnapshot($extractionRow);
        $extractionData = $snapshotId !== null
            ? $this->fitzExtractionNormalizer()->buildPageDataForSnapshot($snapshotId)
            : [];

        if (empty($extractionData)) {
            $rawExtractionData = is_array($extractionRow)
                ? ($extractionRow['extraction_data'] ?? null)
                : ($extractionRow->extraction_data ?? null);
            $extractionData = is_array($rawExtractionData)
                ? $rawExtractionData
                : json_decode((string) $rawExtractionData, true);
        }

        if (!is_array($extractionData)) {
            return 0;
        }

        $annotations = $this->normalizeAnnotationsForPersistence(
            $document,
            $this->buildPromotedStateAnnotationsFromExtractionData($extractionData)
        );
        $groups = $this->buildPromotedStateGroupsFromAnnotations($annotations);
        $annotationIds = array_values(array_unique(array_filter(array_map(
            fn ($annotation) => is_array($annotation) ? $this->durablePdfStateIdentityKeyFromAnnotation($annotation) : '',
            $annotations
        ))));

        $materializedCount = 0;
        DB::transaction(function () use ($document, $annotations, $annotationIds, $snapshotId, &$materializedCount) {
            $sessionId = $this->extractedPdfStateSessionId($document);
            $ownership = $this->resolvePdfStateOwnership($document);

            // Extraction materialization is document-derived state, not user-authored
            // session state. Keep exactly one ownership scope for the fixed extracted
            // session so legacy guest rows do not coexist with later user/admin rows.
            $staleOwnershipRowsQuery = PdfState::query()
                ->where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->where('state', 'extracted');

            if (($ownership['user_id'] ?? null) !== null) {
                $userId = (int) $ownership['user_id'];
                $staleOwnershipRowsQuery->where(function ($query) use ($userId) {
                    $query->whereNull('user_id')
                        ->orWhere('user_id', '!=', $userId)
                        ->orWhereNotNull('admin_id');
                });
            } elseif (($ownership['admin_id'] ?? null) !== null) {
                $adminId = (int) $ownership['admin_id'];
                $staleOwnershipRowsQuery->where(function ($query) use ($adminId) {
                    $query->whereNull('admin_id')
                        ->orWhere('admin_id', '!=', $adminId)
                        ->orWhereNotNull('user_id');
                });
            } else {
                $staleOwnershipRowsQuery->where(function ($query) {
                    $query->whereNotNull('user_id')
                        ->orWhereNotNull('admin_id');
                });
            }

            $staleOwnershipRowsQuery->delete();

            $existingRowsQuery = PdfState::query()
                ->where('document_id', $document->id)
                ->where('state', 'extracted');
            $this->applyPdfStateOwnershipScope($existingRowsQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
            $existingRows = $existingRowsQuery
                ->get()
                ->keyBy(fn (PdfState $record) => $this->durablePdfStateIdentityKeyFromRecord($record));

            $seenIds = [];
            foreach ($annotations as $annotation) {
                if (!is_array($annotation)) {
                    continue;
                }

                $annotationId = $this->durablePdfStateIdentityKeyFromAnnotation($annotation);
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
                        'pdf_extraction_fitz_id' => $snapshotId,
                        'annotation_data' => $annotation,
                        'page_number' => $pageIndex,
                        'session_id' => $sessionId,
                        'user_id' => $ownership['user_id'],
                        'admin_id' => $ownership['admin_id'],
                        'user_email' => ($ownership['user_id'] !== null || $ownership['admin_id'] !== null)
                            ? null
                            : $existing->user_email,
                    ]);
                } else {
                    PdfState::create([
                        'document_id' => $document->id,
                        'pdf_extraction_fitz_id' => $snapshotId,
                        'page_number' => $pageIndex,
                        'annotation_data' => $annotation,
                        'state' => 'extracted',
                        ...$this->pdfStateOwnershipPayload($document, $sessionId),
                    ]);
                }

                $seenIds[$annotationId] = true;
                $materializedCount++;
            }

            $staleRowIds = array_values(array_map(
                static fn (PdfState $record) => $record->id,
                array_filter(
                    $existingRows->all(),
                    static fn ($record, $identityKey) => $identityKey !== '' && !isset($seenIds[$identityKey]),
                    ARRAY_FILTER_USE_BOTH
                )
            ));

            if (!empty($staleRowIds)) {
                $staleRowsQuery = PdfState::query()
                    ->where('document_id', $document->id)
                    ->where('state', 'extracted')
                    ->whereIn('id', $staleRowIds);
                $this->applyPdfStateOwnershipScope($staleRowsQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
                $staleRowsQuery->delete();
            }

            if (empty($annotationIds)) {
                $clearRowsQuery = PdfState::query()
                    ->where('document_id', $document->id)
                    ->where('state', 'extracted');
                $this->applyPdfStateOwnershipScope($clearRowsQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
                $clearRowsQuery->delete();
            }
        });

        $this->syncMaterializedPdfGroups($document, $groups, $snapshotId);

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
        $ownership = $this->resolvePdfStateOwnership($document);
        $mergedKeys = [];

        foreach ($requestedKeys as $key) {
            if (!is_string($key)) {
                continue;
            }
            $normalized = trim($key);
            if ($this->isPromotedSourceKey($normalized)) {
                $mergedKeys[$normalized] = true;
            }
        }

        if ($sessionId !== '') {
            $deletedQuery = PdfState::query()
                ->where('document_id', $document->id)
                ->where('state', 'deleted');
            $this->applyPdfStateOwnershipScope($deletedQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
            $deletedQuery->get(['annotation_data'])
                ->each(function (PdfState $record) use (&$mergedKeys) {
                    $annotationData = is_array($record->annotation_data) ? $record->annotation_data : [];
                    if (
                        !$this->isPromotedSuppressionRecord($record)
                        && empty($annotationData['promotedFromExtraction'])
                    ) {
                        return;
                    }
                    $sourceKey = trim((string) data_get($annotationData, 'promotedSourceKey', ''));
                    if ($this->isPromotedSourceKey($sourceKey)) {
                        $mergedKeys[$sourceKey] = true;
                    }
                });
        }

        foreach ($annotationsPayload as $annotation) {
            if (!is_array($annotation)) {
                continue;
            }

            $activeSourceKey = trim((string) ($annotation['promotedSourceKey'] ?? ''));
            if ($this->isPromotedSourceKey($activeSourceKey)) {
                unset($mergedKeys[$activeSourceKey]);
            }
        }

        $result = array_keys($mergedKeys);
        sort($result);

        return $result;
    }

    private function ensurePromotedSuppressionRecordForSession(
        Document $document,
        string $sessionId,
        string $sourceKey
    ): void {
        $sessionId = trim($sessionId);
        $sourceKey = trim($sourceKey);
        if ($sessionId === '' || !$this->isPromotedSourceKey($sourceKey)) {
            return;
        }

        $ownership = $this->resolvePdfStateOwnership($document);
        $suppressionId = $this->promotedSuppressionAnnotationId($sourceKey);
        $existingRecordQuery = PdfState::query()
            ->where('document_id', $document->id)
            ->where('state', 'deleted')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.id')) = ?", [$suppressionId]);
        $this->applyPdfStateOwnershipScope($existingRecordQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
        $existingRecord = $existingRecordQuery->first();

        $annotationData = [
            'id' => $suppressionId,
            'type' => 'text',
            'promotedFromExtraction' => true,
            'promotedSourceKey' => $sourceKey,
            '_explicitPromotedDelete' => true,
            '_promotedSuppression' => true,
        ];

        if ($existingRecord) {
            $existingRecord->annotation_data = $annotationData;
            $existingRecord->page_number = $this->parsePromotedSourceKeyPageIndex($sourceKey);
            $existingRecord->session_id = $sessionId;
            $existingRecord->user_id = $ownership['user_id'];
            $existingRecord->admin_id = $ownership['admin_id'];
            $existingRecord->user_email = ($ownership['user_id'] !== null || $ownership['admin_id'] !== null)
                ? null
                : $existingRecord->user_email;
            $existingRecord->state = 'deleted';
            $existingRecord->save();
            return;
        }

        PdfState::create([
            'document_id' => $document->id,
            'page_number' => $this->parsePromotedSourceKeyPageIndex($sourceKey),
            'annotation_data' => $annotationData,
            'state' => 'deleted',
            ...$this->pdfStateOwnershipPayload($document, $sessionId),
        ]);
    }

    private function syncDeletedPromotedSourceKeysForSession(
        Document $document,
        string $sessionId,
        array $deletedPromotedSourceKeys
    ): void {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        $ownership = $this->resolvePdfStateOwnership($document);
        $normalizedKeys = array_values(array_unique(array_filter(array_map(
            static fn ($value) => is_string($value) ? trim($value) : '',
            $deletedPromotedSourceKeys
        ), fn ($value) => $this->isPromotedSourceKey((string) $value))));
        $normalizedLookup = array_fill_keys($normalizedKeys, true);

        $existingDeletedRecordsQuery = PdfState::query()
            ->where('document_id', $document->id)
            ->where('state', 'deleted');
        $this->applyPdfStateOwnershipScope($existingDeletedRecordsQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
        $existingDeletedRecords = $existingDeletedRecordsQuery->get();

        $existingBySourceKey = [];
        foreach ($existingDeletedRecords as $record) {
            if (!$this->isPromotedSuppressionRecord($record)) {
                continue;
            }
            $sourceKey = trim((string) data_get($record->annotation_data, 'promotedSourceKey', ''));
            if (!$this->isPromotedSourceKey($sourceKey)) {
                continue;
            }
            $existingBySourceKey[$sourceKey] = $record;
        }

        foreach ($existingBySourceKey as $sourceKey => $record) {
            if (isset($normalizedLookup[$sourceKey])) {
                continue;
            }
            $record->delete();
        }

        foreach ($normalizedKeys as $sourceKey) {
            $existingRecord = $existingBySourceKey[$sourceKey] ?? null;
            if ($existingRecord) {
                continue;
            }
            $this->ensurePromotedSuppressionRecordForSession($document, $sessionId, $sourceKey);
        }
    }

    private function parsePromotedSourceKeyPageIndex(string $sourceKey): ?int
    {
        if (!preg_match('/^block-(\d+)-\d+(?:-.+)?$/', trim($sourceKey), $matches)) {
            return null;
        }

        $pageNumber = (int) ($matches[1] ?? 0);
        return $pageNumber > 0 ? ($pageNumber - 1) : null;
    }

    private function isPromotedSourceKey(string $sourceKey): bool
    {
        return preg_match('/^block-\d+-\d+(?:-.+)?$/', trim($sourceKey)) === 1;
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

        if (!empty($annotation['underline']) || !empty($annotation['strikeout'])) {
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

        $extractionJson = $this->resolveRedrawExtractionJson($document, $userEmail, $sessionId);
        if (!is_string($extractionJson)) {
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
        $documentQuery = $this->applyAccessibleDocumentScope(request(), Document::query())
            ->where(function ($query) {
                $query->whereNull('mode')
                    ->orWhere('mode', '!=', 'regression');
            });

        $documents = $documentQuery
            ->latest()
            ->get();

        if ($this->hasDocumentPreviewColumns()) {
            foreach ($documents->take(8) as $document) {
                if (empty($document->preview_image) && !empty($document->path)) {
                    $this->refreshDocumentPreviewSnapshot($document);
                    $document->refresh();
                }
            }
        }

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

    private function normalizeUploadedDocumentName(string $value): string
    {
        $name = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/u', ' ', trim($value));
        $name = preg_replace('/\s+/u', ' ', (string) $name);
        $name = trim((string) $name);

        if ($name === '') {
            $name = 'document';
        }

        if (!str_ends_with(strtolower($name), '.pdf')) {
            $name = rtrim($name, ". \t\n\r\0\x0B") . '.pdf';
        }

        return $name;
    }

    private function resolveDocumentEditorUrl(Document $document): string
    {
        return match ($document->mode) {
            'guided' => route('documents.guided', $document),
            'ai' => route('documents.ai', $document),
            'full_editor' => route('documents.editPdfjs', $document),
            default => route('documents.editPdfjs', $document),
        };
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'document_mode' => ['nullable', 'string', 'in:editor,regression'],
            'rename_to' => ['nullable', 'string', 'max:240'],
            'allow_duplicate_name' => ['nullable', 'boolean'],
        ]);

        if ($response = $this->consumeMonthlyUploadQuota($request)) {
            return $response;
        }

        $file = $validated['document'];

        $documentMode = is_string($validated['document_mode'] ?? null)
            ? trim((string) $validated['document_mode'])
            : '';
        if ($documentMode === '') {
            $documentMode = 'editor';
        }

        // Resolve the name for this upload. An explicit rename (from the
        // duplicate-name prompt) takes precedence over the uploaded file name.
        $requestedName = is_string($validated['rename_to'] ?? null)
            ? trim((string) $validated['rename_to'])
            : '';
        $effectiveName = $this->normalizeUploadedDocumentName(
            $requestedName !== '' ? $requestedName : (string) $file->getClientOriginalName()
        );

        // Avoid silently creating a second document with the same name. When a
        // match already exists in the user's library (and they haven't chosen
        // to keep both), prompt them to open the existing one or rename this
        // upload instead of creating a duplicate.
        $allowDuplicate = filter_var($validated['allow_duplicate_name'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($documentMode !== 'regression' && !$allowDuplicate) {
            $existing = $this->applyAccessibleDocumentScope($request, Document::query())
                ->where('mode', '!=', 'regression')
                ->whereRaw('LOWER(original_name) = ?', [mb_strtolower($effectiveName)])
                ->latest()
                ->first();

            if ($existing) {
                return response()->json([
                    'duplicate_name' => true,
                    'message' => 'A document named "' . $effectiveName . '" already exists.',
                    'existing_name' => $existing->original_name,
                    'existing_url' => $this->resolveDocumentEditorUrl($existing),
                ], 409);
            }
        }

        Storage::makeDirectory('documents');
        $storedPath = $file->storeAs(
            'documents',
            Str::uuid()->toString() . '.pdf'
        );
        if (!$storedPath || !Storage::exists($storedPath)) {
            Log::error('Failed to persist uploaded PDF before document creation', [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'stored_path' => $storedPath,
            ]);

            return back()
                ->withErrors(['document' => 'Failed to store the uploaded PDF. Please try again.'])
                ->withInput();
        }

        $backupPath = $this->createOriginalBackup($storedPath);

        $document = Document::create([
            ...$this->documentOwnershipPayload(),
            'original_name' => $effectiveName,
            'path' => $storedPath,
            'original_backup_path' => $backupPath,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'mode' => $documentMode,
        ]);

        $userEmail = $this->resolveEditorEmail();
        $sessionId = $request->session()->getId();
        $this->rememberSessionAccessibleDocument($request, $document);
        Cache::put(
            ProcessUploadedDocumentJob::processingCacheKey($document->id),
            true,
            now()->addMinutes(10)
        );
        try {
            ProcessUploadedDocumentJob::dispatch($document->id, $userEmail, $sessionId);
        } catch (\Throwable $exception) {
            Cache::forget(ProcessUploadedDocumentJob::processingCacheKey($document->id));
            throw $exception;
        }

        return redirect()
            ->route('documents.editPdfjs', $document)
            ->with('status', 'PDF uploaded. Document loading continues in the background.');
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
            ...$this->documentOwnershipPayload(),
            'original_name' => 'Blank ' . $pageSize . ' ' . ucfirst($orientation) . '.pdf',
            'path' => $storedRelative,
            'original_backup_path' => $this->createOriginalBackup($storedRelative),
            'mime_type' => 'application/pdf',
            'size_bytes' => filesize($storedFull),
        ]);

        $this->refreshDocumentPreviewSnapshot($document);
        $this->rememberSessionAccessibleDocument($request, $document);

        return redirect()
            ->route('documents.editPdfjs', $document)
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
            ...$this->documentOwnershipPayload(),
            'original_name' => $templateNames[$templateKey],
            'path' => $storedRelative,
            'original_backup_path' => $this->createOriginalBackup($storedRelative),
            'mime_type' => 'application/pdf',
            'size_bytes' => filesize($storedFull),
        ]);

        $this->refreshDocumentPreviewSnapshot($document);
        $this->rememberSessionAccessibleDocument($request, $document);

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
        $template = GuidedTemplate::where('type', 'invoice')
            ->where('slug', $style)
            ->where('is_active', true)
            ->first();
        $templateName = $template?->name ?: 'Invoice';

        // Enforce one template per type: redirect to existing if found
        $existing = $this->applyAccessibleDocumentScope($request, Document::query())
            ->where('mode', 'guided')
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
            ...$this->documentOwnershipPayload(),
            'original_name' => $templateName . ' Guided.pdf',
            'path' => $storedRelative,
            'original_backup_path' => $this->createOriginalBackup($storedRelative),
            'mime_type' => 'application/pdf',
            'size_bytes' => filesize($storedFull),
            'mode' => 'guided',
            'template_type' => 'invoice',
            'template_slug' => $validated['style'] ?? 'default',
            'form_data' => $payload, // Save initial payload as form data
        ]);

        $this->refreshDocumentPreviewSnapshot($document);
        $this->materializePdfAcroFormFields($document, $storedFull, $pythonBinary);
        $this->rememberSessionAccessibleDocument($request, $document);

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
            '_template_type' => ['required', 'string', 'max:100'],
            '_template_slug' => ['required', 'string', 'max:100'],
        ]);

        $templateType = $validated['_template_type'];
        $templateSlug = $validated['_template_slug'];

        // Look up the template to get defaults
        $template = GuidedTemplate::where('type', $templateType)
            ->where('slug', $templateSlug)
            ->where('is_active', true)
            ->first();
        if (!$template) {
            return redirect()->route('documents.index')->withErrors('Template not found.');
        }

        $existing = $this->applyAccessibleDocumentScope($request, Document::query())
            ->where('mode', 'guided')
            ->where('template_type', $templateType)
            ->where('template_slug', $templateSlug)
            ->first();

        if ($existing) {
            return redirect()
                ->route('documents.guided', ['document' => $existing, 'template_type' => $templateType, 'template_slug' => $templateSlug])
                ->with('status', 'You already have this guided template. Editing existing one.');
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
            ...$this->documentOwnershipPayload(),
            'original_name' => $template->name . ' Guided.pdf',
            'path' => $storedRelative,
            'original_backup_path' => $this->createOriginalBackup($storedRelative),
            'mime_type' => 'application/pdf',
            'size_bytes' => filesize($storedFull),
            'mode' => 'guided',
            'template_type' => $templateType,
            'template_slug' => $templateSlug,
            'form_data' => $payload,
        ]);

        $this->refreshDocumentPreviewSnapshot($document);
        $this->rememberSessionAccessibleDocument($request, $document);

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
        $validated['style'] = $validated['style'] ?? ($document->template_slug ?: 'default');

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
            'form_data' => $validated,
        ]);
        $this->refreshDocumentPreviewSnapshot($document);
        $this->materializePdfAcroFormFields($document, $storedFull, $pythonBinary);

        return response()->json([
            'success' => true,
            'python_binary' => $pythonBinary,
            'file_url' => route('documents.file', $document),
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
            'form_data' => $data,
            'template_type' => $document->template_type ?: $templateType,
            'template_slug' => $document->template_slug ?: $templateSlug,
            'mode' => 'guided',
        ]);
        $this->refreshDocumentPreviewSnapshot($document);
        PdfAcroForm::query()
            ->where('document_id', $document->id)
            ->delete();
        $this->materializePdfAcroFormFields($document, $storedFull, $pythonBinary);

        return response()->json([
            'success' => true,
            'file_url' => route('documents.file', $document),
        ]);
    }

    public function convertGuidedAcroForm(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
        $storedFull = Storage::path($document->path);

        if (!file_exists($storedFull)) {
            return response()->json(['error' => 'PDF file not found.'], 404);
        }

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $rawAcroFormEntries = $request->input('acro_form_entries', []);
        if (is_string($rawAcroFormEntries) && trim($rawAcroFormEntries) !== '') {
            $decodedAcroFormEntries = json_decode($rawAcroFormEntries, true);
            if (!is_array($decodedAcroFormEntries)) {
                return response()->json(['error' => 'AcroForm payload is invalid.'], 422);
            }
            $rawAcroFormEntries = $decodedAcroFormEntries;
        }
        $acroFormEntries = $this->normalizeAcroFormEntriesForPersistence(
            is_array($rawAcroFormEntries) ? $rawAcroFormEntries : []
        );

        $sourcePdfPath = $storedFull;
        $appliedPdfPath = null;
        if (!empty($acroFormEntries)) {
            $acroFormApplyResult = $this->applyAcroFormEntriesToPdf($storedFull, $acroFormEntries, $pythonBinary);
            if (!($acroFormApplyResult['success'] ?? false)) {
                Log::error('Guided AcroForm value application failed', [
                    'document_id' => $document->id,
                    'message' => $acroFormApplyResult['message'] ?? null,
                    'error' => $acroFormApplyResult['error'] ?? null,
                ]);
                return response()->json([
                    'error' => $acroFormApplyResult['message'] ?? 'Failed to apply form field values.',
                ], 500);
            }
            $appliedPdfPath = $acroFormApplyResult['output_pdf_path'] ?? null;
            if (is_string($appliedPdfPath) && $appliedPdfPath !== '' && file_exists($appliedPdfPath)) {
                $sourcePdfPath = $appliedPdfPath;
            }
        }
        $guidedLeaseUnderlineAnnotations = $this->buildGuidedLeaseUnderlineShapeAnnotations($acroFormEntries);
        $guidedLeaseFieldTextAnnotations = $this->buildGuidedLeaseFieldTextAnnotations($acroFormEntries);
        $guidedLeaseAnnotations = array_merge($guidedLeaseUnderlineAnnotations, $guidedLeaseFieldTextAnnotations);

        // Fields that became editable text annotations must NOT be baked into
        // the flattened page, or the value would appear twice (baked + overlay).
        $skipBakeFieldNames = [];
        foreach ($guidedLeaseFieldTextAnnotations as $annotation) {
            $skipKey = trim((string) ($annotation['acroFieldKey'] ?? ''));
            if ($skipKey !== '') {
                $skipBakeFieldNames[] = $skipKey;
            }
        }
        foreach ($acroFormEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryKey = trim((string) ($entry['key'] ?? ''));
            $entryFieldName = trim((string) ($entry['fieldName'] ?? ''));
            if ($entryFieldName !== '' && in_array($entryKey, $skipBakeFieldNames, true)) {
                $skipBakeFieldNames[] = $entryFieldName;
            }
        }
        $skipBakeFieldNames = array_values(array_unique(array_filter($skipBakeFieldNames)));

        $skipBakePath = null;
        if (!empty($skipBakeFieldNames)) {
            $skipBakePath = $tempDir . '/guided_acro_skip_' . $document->id . '_' . Str::uuid() . '.json';
            file_put_contents($skipBakePath, json_encode(array_values($skipBakeFieldNames)));
        }

        $outputPath = $tempDir . '/guided_acro_flat_' . $document->id . '_' . Str::uuid() . '.pdf';
        $script = base_path('python/pdf-editor/flatten_acro_form_to_text.py');
        $command = $skipBakePath !== null
            ? sprintf(
                '%s %s %s %s %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($script),
                escapeshellarg($sourcePdfPath),
                escapeshellarg($outputPath),
                escapeshellarg($skipBakePath)
            )
            : sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($script),
                escapeshellarg($sourcePdfPath),
                escapeshellarg($outputPath)
            );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($skipBakePath !== null) {
            @unlink($skipBakePath);
        }

        if ($exitCode !== 0 || !file_exists($outputPath)) {
            @unlink($outputPath);
            if ($appliedPdfPath && $appliedPdfPath !== $storedFull) {
                @unlink($appliedPdfPath);
            }
            Log::error('Guided AcroForm conversion failed', [
                'document_id' => $document->id,
                'exit_code' => $exitCode,
                'output' => implode("\n", $output),
            ]);
            return response()->json(['error' => 'Failed to convert form fields to editable text.'], 500);
        }

        Storage::makeDirectory('documents');
        $convertedStoredRelative = 'documents/' . Str::uuid()->toString() . '.pdf';
        $convertedStoredFull = Storage::path($convertedStoredRelative);

        if (!@copy($outputPath, $convertedStoredFull)) {
            @unlink($outputPath);
            if ($appliedPdfPath && $appliedPdfPath !== $storedFull) {
                @unlink($appliedPdfPath);
            }
            return response()->json(['error' => 'Failed to store converted PDF.'], 500);
        }
        @unlink($outputPath);
        if ($appliedPdfPath && $appliedPdfPath !== $storedFull) {
            @unlink($appliedPdfPath);
        }

        $sessionId = $request->session()->getId();
        if (!empty($acroFormEntries)) {
            $this->upsertPdfAcroFormSessionState(
                $document,
                $sessionId,
                $acroFormEntries,
                $this->resolvePdfStateOwnership($document),
                'saved'
            );
        }

        $baseName = trim(pathinfo((string) $document->original_name, PATHINFO_FILENAME));
        if ($baseName === '') {
            $baseName = 'Converted guided document';
        }
        $convertedDocument = Document::create([
            ...$this->documentOwnershipPayload($document),
            'original_name' => $baseName . ' - Full Editor.pdf',
            'path' => $convertedStoredRelative,
            'original_backup_path' => $this->createOriginalBackup($convertedStoredRelative),
            'mime_type' => 'application/pdf',
            'size_bytes' => filesize($convertedStoredFull),
            'mode' => 'full_editor',
            'template_type' => null,
            'template_slug' => null,
            'form_data' => null,
        ]);

        $this->rememberSessionAccessibleDocument($request, $convertedDocument);

        $this->refreshDocumentPreviewSnapshot($convertedDocument);

        $userEmail = $this->resolveEditorEmail();
        [$extractCode, $extractOutput] = $this->runFitzExtraction(
            $convertedDocument,
            $convertedStoredFull,
            $userEmail,
            $sessionId,
            $pythonBinary
        );

        if ($extractCode !== 0) {
            Log::warning('Converted guided PDF extraction failed', [
                'document_id' => $convertedDocument->id,
                'source_guided_document_id' => $document->id,
                'exit_code' => $extractCode,
                'output' => implode("\n", $extractOutput),
            ]);
        }

        if (!empty($guidedLeaseAnnotations)) {
            $this->persistGuidedLeaseUnderlineShapeAnnotations(
                $convertedDocument,
                $sessionId,
                $guidedLeaseAnnotations
            );
        }

        return response()->json([
            'success' => true,
            'edit_url' => route('documents.editPdfjs', $convertedDocument),
            'guided_url' => route('documents.guided', $document),
            'document_id' => $convertedDocument->id,
            'guided_document_id' => $document->id,
            'message' => 'Created a full-editor copy. The guided document was kept as guided.',
        ]);
    }

    private function buildGuidedLeaseUnderlineShapeAnnotations(array $acroFormEntries): array
    {
        $annotations = [];

        foreach ($acroFormEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $fieldKey = trim((string) ($entry['key'] ?? $entry['fieldName'] ?? ''));
            if (!str_starts_with($fieldKey, 'lease_') || str_starts_with($fieldKey, 'lease_sig_')) {
                continue;
            }

            $rect = is_array($entry['rect'] ?? null) ? array_values($entry['rect']) : [];
            if (count($rect) < 4) {
                continue;
            }

            $x0 = is_numeric($rect[0]) ? (float) $rect[0] : null;
            $y0 = is_numeric($rect[1]) ? (float) $rect[1] : null;
            $x1 = is_numeric($rect[2]) ? (float) $rect[2] : null;
            $y1 = is_numeric($rect[3] ?? null) ? (float) $rect[3] : null;
            if ($x0 === null || $y0 === null || $x1 === null || $x1 <= $x0) {
                continue;
            }

            $pageIndex = isset($entry['pageIndex']) && is_numeric($entry['pageIndex'])
                ? max(0, (int) $entry['pageIndex'])
                : 0;
            $strokeWidth = 1.0;
            // Anchor the underline to the field's own text line (the top of the
            // widget rect) instead of the rect bottom. Tall widget rects reach
            // down into the following line; an underline drawn there overlaps
            // that paragraph and looks detached from its own value.
            $rawHeight = ($y1 !== null && $y1 > $y0) ? ($y1 - $y0) : 0.0;
            if ($rawHeight > 0.0) {
                $fontSize = min(9.0, $rawHeight * 0.85);
                if ($fontSize < 6.0) {
                    $fontSize = 6.0;
                }
                $lineBoxHeight = min($rawHeight, $fontSize * 1.2);
                $lineBottomY = ($y0 + $rawHeight) - $lineBoxHeight;
            } else {
                $lineBottomY = $y0;
            }
            $pdfY = max(0.0, $lineBottomY - ($strokeWidth / 2) - 1.5);
            $id = 'guided_lease_underline_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $fieldKey);

            $annotations[] = [
                'id' => $id,
                'type' => 'shape',
                'shapeType' => 'line',
                'pageIndex' => $pageIndex,
                'pdfX' => $x0,
                'pdfY' => $pdfY,
                'pdfWidth' => $x1 - $x0,
                'pdfHeight' => $strokeWidth,
                'strokeColor' => '#111827',
                'strokeOpacity' => 1,
                'strokeWidth' => $strokeWidth,
                'strokeTransparent' => false,
                'fillColor' => '#ffffff',
                'fillOpacity' => 0,
                'fillTransparent' => true,
                'lineStartX' => 0,
                'lineStartY' => 0.5,
                'lineEndX' => 1,
                'lineEndY' => 0.5,
                'lineCap' => 'butt',
                'opacity' => 1,
                'rotation' => 0,
                'locked' => false,
                'guidedLeaseUnderline' => true,
                'acroFieldKey' => $fieldKey,
            ];
        }

        return $annotations;
    }

    /**
     * Build editable text-overlay annotations for each filled guided-lease
     * field. Pairs with buildGuidedLeaseUnderlineShapeAnnotations(): the
     * underline becomes a shape the user can move, and the value becomes a
     * standalone editable text box (instead of being baked into the page,
     * where it merges into one un-editable source run).
     */
    private function buildGuidedLeaseFieldTextAnnotations(array $acroFormEntries): array
    {
        $annotations = [];

        foreach ($acroFormEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $fieldKey = trim((string) ($entry['key'] ?? $entry['fieldName'] ?? ''));
            if ($fieldKey === '') {
                continue;
            }
            // Signature fields stay baked into the page (never become editable text).
            if (str_starts_with($fieldKey, 'lease_sig_')) {
                continue;
            }
            // Only real text/value fields become editable overlay boxes. Checkboxes,
            // radio buttons, push buttons and signature widgets keep their baked
            // appearance so their mark stays glued to the correct spot. Without this,
            // a field value would be flattened into the surrounding template line and
            // become un-editable (clicking the value selects the whole line).
            $fieldType = strtoupper(trim((string) ($entry['fieldType'] ?? '')));
            $isNonTextField = (bool) ($entry['checkBox'] ?? false)
                || (bool) ($entry['radioButton'] ?? false)
                || in_array($fieldType, ['BTN', 'SIG'], true);
            if ($isNonTextField) {
                continue;
            }

            $value = $entry['value'] ?? '';
            if (is_array($value)) {
                $value = implode("\n", array_map(static fn ($item) => (string) ($item ?? ''), $value));
            } elseif (is_bool($value)) {
                $value = $value ? 'X' : '';
            } else {
                $value = (string) $value;
            }
            if (trim($value) === '') {
                continue;
            }

            $rect = is_array($entry['rect'] ?? null) ? array_values($entry['rect']) : [];
            if (count($rect) < 4) {
                continue;
            }

            $x0 = is_numeric($rect[0]) ? (float) $rect[0] : null;
            $y0 = is_numeric($rect[1]) ? (float) $rect[1] : null;
            $x1 = is_numeric($rect[2]) ? (float) $rect[2] : null;
            $y1 = is_numeric($rect[3]) ? (float) $rect[3] : null;
            if ($x0 === null || $y0 === null || $x1 === null || $y1 === null || $x1 <= $x0 || $y1 <= $y0) {
                continue;
            }

            $pageIndex = isset($entry['pageIndex']) && is_numeric($entry['pageIndex'])
                ? max(0, (int) $entry['pageIndex'])
                : 0;
            $multiLine = (bool) ($entry['multiLine'] ?? false);

            $width = $x1 - $x0;
            $rawHeight = $y1 - $y0;
            // Match the 9pt body text used by the lease generator, shrinking
            // only for unusually short field boxes.
            $fontSize = min(9.0, $rawHeight * 0.85);
            if ($fontSize < 6.0) {
                $fontSize = 6.0;
            }
            $lineHeight = $fontSize * 1.2;
            // A single-line field's widget rect can be much taller than one line
            // of text. Keep its editable/clickable box to a single line, anchored
            // to the top of the field, so it never extends down over the paragraph
            // line below and swallows the pointer events that line needs to be
            // edited. Multi-line fields keep their full height.
            $height = $rawHeight;
            if (!$multiLine && $rawHeight > $lineHeight) {
                $height = $lineHeight;
                $y0 = ($y0 + $rawHeight) - $height;
            }

            $id = 'guided_lease_field_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $fieldKey);
            $uid = 'guided_lease_field_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $fieldKey);

            $annotations[] = [
                'id' => $id,
                'type' => 'text',
                'pageIndex' => $pageIndex,
                'text' => $value,
                'originalText' => '',
                'pdfX' => $x0,
                'pdfY' => $y0,
                'pdfWidth' => $width,
                'pdfHeight' => $height,
                'fontSize' => $fontSize,
                'requestedFontSize' => $fontSize,
                'lineHeight' => $lineHeight,
                'fontFamily' => 'Helvetica',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#111827',
                'color' => '#111827',
                'backgroundColor' => 'transparent',
                'opacity' => 1,
                'underline' => false,
                'strikeout' => false,
                'textAlign' => 'left',
                'verticalAlign' => $multiLine ? 'top' : 'middle',
                'locked' => false,
                'zIndex' => 7,
                'savedTextOverlay' => true,
                'styleDirty' => true,
                'userCreated' => true,
                'userAuthored' => true,
                'userSizedTextBox' => true,
                'userForcedRichText' => true,
                'pdfjsEditorMode' => 'rich',
                'skipPdfjsSourceMask' => true,
                'pdfjsAnchorUid' => $uid,
                'guidedLeaseField' => true,
                'acroFieldKey' => $fieldKey,
                '_originalBox' => ['x' => $x0, 'y' => $y0, 'w' => $width, 'h' => $height],
                '_originalPdfBox' => ['x' => $x0, 'y' => $y0, 'w' => $width, 'h' => $height],
            ];
        }

        return $annotations;
    }

    private function persistGuidedLeaseUnderlineShapeAnnotations(
        Document $document,
        string $sessionId,
        array $annotations
    ): void {
        if ($sessionId === '' || empty($annotations)) {
            return;
        }

        $ownership = $this->pdfStateOwnershipPayload($document, $sessionId);
        $shapeSessionId = (($ownership['user_id'] ?? null) === null && ($ownership['admin_id'] ?? null) === null)
            ? 'document_' . $document->id . '_extracted'
            : $sessionId;
        $ownership['session_id'] = $shapeSessionId;

        DB::transaction(function () use ($document, $shapeSessionId, $annotations, $ownership) {
            $cleanupQuery = PdfState::query()
                ->where('document_id', $document->id)
                ->where(function ($query) {
                    $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.id')) LIKE ?", ['guided_lease_underline_%'])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.id')) LIKE ?", ['guided_lease_field_%']);
                });
            if (($ownership['user_id'] ?? null) !== null) {
                $cleanupQuery->where('user_id', $ownership['user_id']);
            } elseif (($ownership['admin_id'] ?? null) !== null) {
                $cleanupQuery->where('admin_id', $ownership['admin_id']);
            } else {
                $cleanupQuery->where(function ($query) use ($shapeSessionId) {
                    $query->where('session_id', $shapeSessionId)
                        ->orWhere(function ($guestQuery) {
                            $guestQuery->whereNull('user_id')
                                ->whereNull('admin_id');
                        });
                });
            }
            $cleanupQuery->delete();

            foreach ($annotations as $annotation) {
                PdfState::create([
                    'document_id' => $document->id,
                    'page_number' => $annotation['pageIndex'] ?? 0,
                    'annotation_data' => $annotation,
                    'state' => 'saved',
                    ...$ownership,
                ]);
            }
        });
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
     * Convert HTML content to PDF using WeasyPrint.
     */
    public function convertHtmlToPdf(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('weasyprint');

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

        $groupedExtractionData = [];
        if (
            strtolower((string) config('pdf_editor.mode', 'fitz_extraction')) === 'annotation_base'
            || strtolower((string) config('pdf_editor.layout_mode', 'default')) === 'bounding_box_edit'
        ) {
            try {
                $groupedExtractionData = $this->buildAnnotationBaseExtractionData($document);
            } catch (\Throwable $error) {
                \Log::warning('Failed to build grouped annotation-base extraction data', [
                    'document_id' => $document->id,
                    'error' => $error->getMessage(),
                ]);
                $groupedExtractionData = [];
            }
        }

        return response()->json([
            'success' => true,
            'extraction_data' => json_decode($extraction->extraction_data, true),
            'grouped_extraction_data' => $groupedExtractionData,
            'total_pages' => $extraction->total_pages,
            'total_words' => $extraction->total_words,
            'full_text' => $extraction->full_text,
            'embedded_fonts' => $embeddedFonts,
        ]);
    }

    public function edit(Request $request, Document $document)
    {
        return view('documents.edit', [
            'document' => $document,
            'activeTab' => 'pdf-editor',
            'pdfSaveMode' => strtolower((string) config('pdf_editor.save_mode', 'full_page_save')),
            'savedEditPreviewUrl' => route('documents.savedEdit', $document),
        ]);
    }

    public function editNew(\Illuminate\Http\Request $request, Document $document)
    {
        if ($document->mode === 'guided') {
            return redirect()->route('documents.guided', $document);
        }

        // Staged migration: ?pdfjs=1 mounts the official pdf.js PDFViewer +
        // editor stack (canvasWrapper / textLayer / annotationLayer /
        // annotationEditorLayer) against the redacted clean PDF. Default
        // continues to render the existing custom overlay editor.
        if ($request->query('pdfjs') === '1') {
            return view('documents.edit-new-pdfjs', [
                'document' => $document,
            ]);
        }
        return view('documents.edit-new', [
            'document' => $document,
        ]);
    }

    public function editPdfjs(Document $document)
    {
        if ($document->mode === 'guided') {
            return redirect()->route('documents.guided', $document);
        }

        return redirect()->route('documents.editNew', [
            'document' => $document,
            'pdfjs' => 1,
        ]);
    }

    private function documentNotesQuery(Document $document)
    {
        $ownership = $this->resolveDocumentOwnership($document);

        return DocumentNote::query()
            ->where('document_id', $document->id)
            ->where('user_id', $ownership['user_id'])
            ->where('admin_id', $ownership['admin_id']);
    }

    private function requireDocumentNotesAuthentication()
    {
        if ($this->hasEditorAuthentication()) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Notes are a premium feature for logged-in users.',
        ], 401);
    }

    private function serializeDocumentNote(DocumentNote $note): array
    {
        return [
            'id' => $note->id,
            'document_id' => $note->document_id,
            'page_index' => $note->page_index,
            'page_number' => $note->page_index !== null ? $note->page_index + 1 : null,
            'anchor_x' => $note->anchor_x,
            'anchor_y' => $note->anchor_y,
            'has_anchor' => $note->page_index !== null && $note->anchor_x !== null && $note->anchor_y !== null,
            'pin_color' => $this->normalizeDocumentNotePinColor($note->pin_color),
            'pin_icon' => $this->normalizeDocumentNotePinIcon($note->pin_icon),
            'body' => $note->body,
            'created_at' => optional($note->created_at)->toIso8601String(),
            'updated_at' => optional($note->updated_at)->toIso8601String(),
        ];
    }

    private function normalizeDocumentNotePinColor(?string $color): string
    {
        $color = strtolower(trim((string) $color));

        return in_array($color, self::DOCUMENT_NOTE_PIN_COLORS, true)
            ? $color
            : self::DOCUMENT_NOTE_PIN_COLORS[0];
    }

    private function normalizeDocumentNotePinIcon(?string $icon): string
    {
        $icon = strtolower(trim((string) $icon));

        return in_array($icon, self::DOCUMENT_NOTE_PIN_ICONS, true)
            ? $icon
            : self::DOCUMENT_NOTE_PIN_ICONS[0];
    }

    private function normalizeDocumentNoteAnchor(array $validated): array
    {
        $pageIndex = $validated['page_index'] ?? null;
        $anchorX = array_key_exists('anchor_x', $validated) ? $validated['anchor_x'] : null;
        $anchorY = array_key_exists('anchor_y', $validated) ? $validated['anchor_y'] : null;

        if ($pageIndex === null || $anchorX === null || $anchorY === null) {
            return [$pageIndex, null, null];
        }

        return [$pageIndex, (float) $anchorX, (float) $anchorY];
    }

    private function ownedDocumentNoteOrFail(Document $document, DocumentNote $note): DocumentNote
    {
        $owned = $this->documentNotesQuery($document)
            ->whereKey($note->id)
            ->first();

        abort_if($owned === null, 404);

        return $owned;
    }

    public function getDocumentNotes(Request $request, Document $document)
    {
        if ($response = $this->requireDocumentNotesAuthentication()) {
            return $response;
        }

        $notes = $this->documentNotesQuery($document)
            ->orderByRaw('page_index is null')
            ->orderBy('page_index')
            ->latest('updated_at')
            ->get()
            ->map(fn (DocumentNote $note) => $this->serializeDocumentNote($note))
            ->values();

        return response()->json([
            'success' => true,
            'notes' => $notes,
        ]);
    }

    public function storeDocumentNote(Request $request, Document $document)
    {
        if ($response = $this->requireDocumentNotesAuthentication()) {
            return $response;
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'page_index' => ['nullable', 'integer', 'min:0'],
            'anchor_x' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'anchor_y' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'pin_color' => ['nullable', Rule::in(self::DOCUMENT_NOTE_PIN_COLORS)],
            'pin_icon' => ['nullable', Rule::in(self::DOCUMENT_NOTE_PIN_ICONS)],
        ]);

        $body = trim((string) $validated['body']);
        if ($body === '') {
            return response()->json([
                'success' => false,
                'message' => 'Write a note before saving.',
            ], 422);
        }

        $ownership = $this->resolveDocumentOwnership($document);
        [$pageIndex, $anchorX, $anchorY] = $this->normalizeDocumentNoteAnchor($validated);
        $note = DocumentNote::create([
            'document_id' => $document->id,
            'user_id' => $ownership['user_id'],
            'admin_id' => $ownership['admin_id'],
            'page_index' => $pageIndex,
            'anchor_x' => $anchorX,
            'anchor_y' => $anchorY,
            'pin_color' => $this->normalizeDocumentNotePinColor($validated['pin_color'] ?? null),
            'pin_icon' => $this->normalizeDocumentNotePinIcon($validated['pin_icon'] ?? null),
            'body' => $body,
        ]);

        return response()->json([
            'success' => true,
            'note' => $this->serializeDocumentNote($note),
        ], 201);
    }

    public function updateDocumentNote(Request $request, Document $document, DocumentNote $note)
    {
        if ($response = $this->requireDocumentNotesAuthentication()) {
            return $response;
        }

        $note = $this->ownedDocumentNoteOrFail($document, $note);
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'page_index' => ['nullable', 'integer', 'min:0'],
            'anchor_x' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'anchor_y' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'pin_color' => ['nullable', Rule::in(self::DOCUMENT_NOTE_PIN_COLORS)],
            'pin_icon' => ['nullable', Rule::in(self::DOCUMENT_NOTE_PIN_ICONS)],
        ]);

        $body = trim((string) $validated['body']);
        if ($body === '') {
            return response()->json([
                'success' => false,
                'message' => 'A note cannot be empty.',
            ], 422);
        }

        [$pageIndex, $anchorX, $anchorY] = $this->normalizeDocumentNoteAnchor($validated);
        $note->fill([
            'page_index' => $pageIndex,
            'anchor_x' => $anchorX,
            'anchor_y' => $anchorY,
            'pin_color' => $this->normalizeDocumentNotePinColor($validated['pin_color'] ?? $note->pin_color),
            'pin_icon' => $this->normalizeDocumentNotePinIcon($validated['pin_icon'] ?? $note->pin_icon),
            'body' => $body,
        ])->save();

        return response()->json([
            'success' => true,
            'note' => $this->serializeDocumentNote($note->fresh()),
        ]);
    }

    public function deleteDocumentNote(Request $request, Document $document, DocumentNote $note)
    {
        if ($response = $this->requireDocumentNotesAuthentication()) {
            return $response;
        }

        $note = $this->ownedDocumentNoteOrFail($document, $note);
        $note->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    public function editPdfjsRewriteTj(Request $request, Document $document)
    {
        $isMultipart = $request->isJson() === false && $request->hasFile('pdf');
        if ($isMultipart) {
            $editsRaw = $request->input('edits');
            if (is_string($editsRaw)) {
                $editsRaw = json_decode($editsRaw, true);
            }
            if (!is_array($editsRaw) || count($editsRaw) === 0) {
                return response()->json(['error' => 'edits[] missing or invalid'], 422);
            }
            $request->merge(['edits' => $editsRaw]);
        }

        $validated = $request->validate([
            'edits' => ['required', 'array', 'min:1'],
            'edits.*.page' => ['required', 'integer', 'min:0'],
            'edits.*.match_xref' => ['nullable', 'integer'],
            'edits.*.original_text' => ['required', 'string'],
            'edits.*.new_text' => ['required', 'string'],
            'edits.*.occurrence' => ['nullable', 'integer', 'min:0'],
        ]);

        $tmpIn = tempnam(sys_get_temp_dir(), 'editpdfjs_in_') . '.pdf';
        if ($isMultipart) {
            $upload = $request->file('pdf');
            if (!$upload->isValid()) {
                @unlink($tmpIn);
                return response()->json(['error' => 'Invalid uploaded PDF'], 422);
            }
            move_uploaded_file($upload->getRealPath(), $tmpIn);
        } else {
            if (!$document->path || !Storage::exists($document->path)) {
                @unlink($tmpIn);
                return response()->json(['error' => 'Current PDF missing'], 404);
            }
            copy(Storage::path($document->path), $tmpIn);
        }
        $tmpOut = tempnam(sys_get_temp_dir(), 'editpdfjs_out_') . '.pdf';
        $editsJsonPath = tempnam(sys_get_temp_dir(), 'editpdfjs_edits_') . '.json';
        file_put_contents($editsJsonPath, json_encode($validated['edits']));

        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('pikepdf');
        $script = base_path('python/pdf-editor/rewrite_tj_inplace.py');

        $cmd = sprintf(
            '%s %s --in %s --out %s --edits %s 2>&1',
            escapeshellcmd($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($tmpIn),
            escapeshellarg($tmpOut),
            escapeshellarg($editsJsonPath)
        );
        exec($cmd, $output, $exitCode);
        @unlink($tmpIn);
        @unlink($editsJsonPath);

        if ($exitCode !== 0 || !file_exists($tmpOut)) {
            @unlink($tmpOut);
            return response()->json([
                'error' => 'Rewrite failed',
                'stdout' => implode("\n", $output),
                'exit' => $exitCode,
            ], 500);
        }

        $bytes = file_get_contents($tmpOut);
        @unlink($tmpOut);
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="edited.pdf"',
        ]);
    }

    public function editPdfjsRedactSourceText(Request $request, Document $document)
    {
        $isMultipart = $request->isJson() === false && $request->hasFile('pdf');
        if ($isMultipart) {
            $editsRaw = $request->input('edits');
            if (is_string($editsRaw)) {
                $editsRaw = json_decode($editsRaw, true);
            }
            if (!is_array($editsRaw) || count($editsRaw) === 0) {
                return response()->json(['error' => 'edits[] missing or invalid'], 422);
            }
            $request->merge(['edits' => $editsRaw]);
        }

        $validated = $request->validate([
            'edits' => ['required', 'array', 'min:1'],
            'edits.*.page' => ['required', 'integer', 'min:0'],
            'edits.*.original_text' => ['nullable', 'string'],
            'edits.*.occurrence' => ['nullable', 'integer', 'min:0'],
            'edits.*.source_bbox' => ['nullable', 'array'],
            'edits.*.source_bbox.x' => ['required_with:edits.*.source_bbox', 'numeric'],
            'edits.*.source_bbox.y' => ['required_with:edits.*.source_bbox', 'numeric'],
            'edits.*.source_bbox.w' => ['required_with:edits.*.source_bbox', 'numeric'],
            'edits.*.source_bbox.h' => ['required_with:edits.*.source_bbox', 'numeric'],
        ]);

        $tmpIn = tempnam(sys_get_temp_dir(), 'editpdfjs_redactin_') . '.pdf';
        if ($isMultipart) {
            $upload = $request->file('pdf');
            if (!$upload->isValid()) {
                @unlink($tmpIn);
                return response()->json(['error' => 'Invalid uploaded PDF'], 422);
            }
            move_uploaded_file($upload->getRealPath(), $tmpIn);
        } else {
            if (!$document->path || !Storage::exists($document->path)) {
                @unlink($tmpIn);
                return response()->json(['error' => 'Current PDF missing'], 404);
            }
            copy(Storage::path($document->path), $tmpIn);
        }

        $tmpOut = tempnam(sys_get_temp_dir(), 'editpdfjs_redactout_') . '.pdf';
        $editsJsonPath = tempnam(sys_get_temp_dir(), 'editpdfjs_redact_edits_') . '.json';
        file_put_contents($editsJsonPath, json_encode($validated['edits']));

        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
        $script = base_path('python/pdf-editor/redact_pdfjs_source_text.py');

        $cmd = sprintf(
            '%s %s --in %s --out %s --edits %s 2>&1',
            escapeshellcmd($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($tmpIn),
            escapeshellarg($tmpOut),
            escapeshellarg($editsJsonPath)
        );
        exec($cmd, $output, $exitCode);
        @unlink($tmpIn);
        @unlink($editsJsonPath);

        if ($exitCode !== 0 || !file_exists($tmpOut)) {
            @unlink($tmpOut);
            return response()->json([
                'error' => 'Source text redaction failed',
                'stdout' => implode("\n", $output),
                'exit' => $exitCode,
            ], 500);
        }

        $bytes = file_get_contents($tmpOut);
        @unlink($tmpOut);
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="source-redacted.pdf"',
        ]);
    }

    /**
     * Irreversibly flatten one shape or direct-draw layer into the current PDF.
     * Text touched by the layer's bounding box is removed before the visual
     * layer is stamped, so the burned content cannot be selected or restored
     * by the editor's annotation undo stack.
     */
    public function editPdfjsBurnLayer(Request $request, Document $document)
    {
        $annotationRaw = $request->input('annotation');
        if (is_string($annotationRaw)) {
            $annotationRaw = json_decode($annotationRaw, true);
            $request->merge(['annotation' => $annotationRaw]);
        }

        $validated = $request->validate([
            'annotation' => ['required', 'array'],
            'session_id' => ['nullable', 'string'],
        ]);

        $normalized = $this->normalizeAnnotationsForPersistence($document, [$validated['annotation']]);
        $annotation = is_array($normalized[0] ?? null) ? $normalized[0] : [];
        $type = strtolower(trim((string) ($annotation['type'] ?? '')));
        $isDirectDrawing = $type === 'image'
            && strtolower(trim((string) ($annotation['imageToolSource'] ?? ''))) === 'direct-draw';

        // Text added with the Text tool can be burned as well. Source text is left
        // out on purpose: burning it would redact the page's own words and stamp
        // the identical words back over the hole.
        $isUserCreatedText = $type === 'text' && (
            filter_var($annotation['userCreated'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($annotation['userAuthored'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($annotation['skipPdfjsSourceMask'] ?? false, FILTER_VALIDATE_BOOLEAN)
        );

        if ($type !== 'shape' && !$isDirectDrawing && !$isUserCreatedText) {
            return response()->json([
                'success' => false,
                'message' => 'Only shapes, direct drawings, and text added with the Text tool can be burned into the PDF.',
            ], 422);
        }

        $bbox = [
            'x' => $annotation['pdfX'] ?? null,
            'y' => $annotation['pdfY'] ?? null,
            'w' => $annotation['pdfWidth'] ?? null,
            'h' => $annotation['pdfHeight'] ?? null,
        ];
        if (!is_numeric($bbox['x']) || !is_numeric($bbox['y'])
            || !is_numeric($bbox['w']) || !is_numeric($bbox['h'])
            || (float) $bbox['w'] <= 0 || (float) $bbox['h'] <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'The layer does not have a valid PDF bounding box.',
            ], 422);
        }

        if (!$document->path || !Storage::exists($document->path)) {
            return response()->json(['success' => false, 'message' => 'Current PDF missing.'], 404);
        }

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }
        $token = (string) Str::uuid();
        $workingPdfPath = $tempDir . '/burn_layer_in_' . $document->id . '_' . $token . '.pdf';
        $redactedPdfPath = $tempDir . '/burn_layer_out_' . $document->id . '_' . $token . '.pdf';
        $editsPath = $tempDir . '/burn_layer_edits_' . $document->id . '_' . $token . '.json';
        $annotationsPath = $tempDir . '/burn_layer_ann_' . $document->id . '_' . $token . '.json';
        $backupPath = $tempDir . '/burn_layer_backup_' . $document->id . '_' . $token . '.pdf';
        $cleanup = static function () use ($workingPdfPath, $redactedPdfPath, $editsPath, $annotationsPath, $backupPath): void {
            foreach ([$workingPdfPath, $redactedPdfPath, $editsPath, $annotationsPath, $backupPath] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        };

        $sourcePath = Storage::path($document->path);
        if ($request->hasFile('pdf')) {
            $upload = $request->file('pdf');
            if (!$upload->isValid() || !@copy($upload->getRealPath(), $workingPdfPath)) {
                $cleanup();
                return response()->json(['success' => false, 'message' => 'Invalid uploaded PDF.'], 422);
            }
        } elseif (!@copy($sourcePath, $workingPdfPath)) {
            $cleanup();
            return response()->json(['success' => false, 'message' => 'Failed to prepare the PDF working copy.'], 500);
        }

        $edits = [[
            'page' => max(0, (int) ($annotation['pageIndex'] ?? 0)),
            'source_bbox' => array_map(static fn ($value) => (float) $value, $bbox),
            'redact_bbox_only' => true,
            'remove_graphics' => false,
            'fill_white' => false,
        ]];
        $prepared = $this->prepareAnnotationsForPython([$annotation]);
        $prepared = array_map(static function ($entry) use ($document) {
            if (is_array($entry)) {
                $entry['__documentId'] = $document->id;
            }
            return $entry;
        }, $prepared);

        if (empty($prepared)
            || @file_put_contents($editsPath, json_encode($edits, JSON_INVALID_UTF8_SUBSTITUTE)) === false
            || @file_put_contents($annotationsPath, json_encode($prepared, JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
            $cleanup();
            return response()->json(['success' => false, 'message' => 'Failed to prepare the burn payload.'], 500);
        }

        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
        $redactCommand = sprintf(
            '%s %s --in %s --out %s --edits %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg(base_path('python/pdf-editor/redact_pdfjs_source_text.py')),
            escapeshellarg($workingPdfPath),
            escapeshellarg($redactedPdfPath),
            escapeshellarg($editsPath)
        );
        $redactOutput = [];
        $redactExitCode = 0;
        exec($redactCommand, $redactOutput, $redactExitCode);
        if ($redactExitCode !== 0 || !is_file($redactedPdfPath)) {
            $cleanup();
            return response()->json([
                'success' => false,
                'message' => 'Failed to erase text behind the layer.',
                'error' => implode("\n", $redactOutput),
            ], 500);
        }

        $stampCommand = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg(base_path('python/pdf-editor/apply_annotations_direct_new.py')),
            escapeshellarg($redactedPdfPath),
            escapeshellarg($annotationsPath)
        );
        $stampOutput = [];
        $stampExitCode = 0;
        exec($stampCommand, $stampOutput, $stampExitCode);
        if ($stampExitCode !== 0 || !is_file($redactedPdfPath)) {
            $cleanup();
            return response()->json([
                'success' => false,
                'message' => 'Failed to burn the layer into the PDF.',
                'error' => implode("\n", $stampOutput),
            ], 500);
        }

        @copy($sourcePath, $backupPath);
        if (!@copy($redactedPdfPath, $sourcePath)) {
            if (is_file($backupPath)) {
                @copy($backupPath, $sourcePath);
            }
            $cleanup();
            return response()->json(['success' => false, 'message' => 'Failed to write the burned PDF.'], 500);
        }

        $sessionId = trim((string) ($validated['session_id'] ?? ''));
        $annotationId = trim((string) ($annotation['id'] ?? ''));
        if ($annotationId !== '') {
            $this->applyAnnotationDeletions($document, $sessionId, [$annotationId], []);
        }

        $document->size_bytes = @filesize($sourcePath) ?: $document->size_bytes;
        $document->updated_at = now();
        $document->saveQuietly();
        $bytes = file_get_contents($sourcePath);
        $cleanup();

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="burned-layer.pdf"',
            'X-Burned-Annotation-Id' => $annotationId,
        ]);
    }

    public function editPdfjsMoveTj(Request $request, Document $document)
    {
        $isMultipart = $request->isJson() === false && $request->hasFile('pdf');
        if ($isMultipart) {
            $movesRaw = $request->input('moves');
            if (is_string($movesRaw)) {
                $movesRaw = json_decode($movesRaw, true);
            }
            if (!is_array($movesRaw) || count($movesRaw) === 0) {
                return response()->json(['error' => 'moves[] missing or invalid'], 422);
            }
            $request->merge(['moves' => $movesRaw]);
        }

        $validated = $request->validate([
            'moves' => ['required', 'array', 'min:1'],
            'moves.*.page' => ['required', 'integer', 'min:0'],
            'moves.*.original_text' => ['nullable', 'string'],
            'moves.*.occurrence' => ['nullable', 'integer', 'min:0'],
            'moves.*.source_bbox' => ['nullable', 'array'],
            'moves.*.source_bbox.x' => ['required_with:moves.*.source_bbox', 'numeric'],
            'moves.*.source_bbox.y' => ['required_with:moves.*.source_bbox', 'numeric'],
            'moves.*.source_bbox.w' => ['required_with:moves.*.source_bbox', 'numeric'],
            'moves.*.source_bbox.h' => ['required_with:moves.*.source_bbox', 'numeric'],
            'moves.*.lock_rects' => ['nullable', 'array'],
            'moves.*.lock_rects.*' => ['array'],
            'moves.*.lock_rects.*.x' => ['required', 'numeric'],
            'moves.*.lock_rects.*.y' => ['required', 'numeric'],
            'moves.*.lock_rects.*.w' => ['required', 'numeric'],
            'moves.*.lock_rects.*.h' => ['required', 'numeric'],
            'moves.*.dx_pts' => ['required', 'numeric'],
            'moves.*.dy_pts' => ['required', 'numeric'],
        ]);

        $tmpIn = tempnam(sys_get_temp_dir(), 'editpdfjs_movein_') . '.pdf';
        if ($isMultipart) {
            // Client uploaded the *current* PDF state; subsequent moves
            // must operate on the just-rewritten bytes, not the pristine
            // original_backup_path, otherwise the source_bbox derived
            // from the moved render won't match anything.
            $upload = $request->file('pdf');
            if (!$upload->isValid()) {
                @unlink($tmpIn);
                return response()->json(['error' => 'Invalid uploaded PDF'], 422);
            }
            move_uploaded_file($upload->getRealPath(), $tmpIn);
        } else {
            if (!$document->path || !Storage::exists($document->path)) {
                @unlink($tmpIn);
                return response()->json(['error' => 'Current PDF missing'], 404);
            }
            copy(Storage::path($document->path), $tmpIn);
        }

        $tmpOut = tempnam(sys_get_temp_dir(), 'editpdfjs_moveout_') . '.pdf';
        $movesJsonPath = tempnam(sys_get_temp_dir(), 'editpdfjs_moves_') . '.json';
        file_put_contents($movesJsonPath, json_encode($validated['moves']));

        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('pikepdf');
        $script = base_path('python/pdf-editor/move_text_inplace.py');

        $cmd = sprintf(
            '%s %s --in %s --out %s --moves %s 2>&1',
            escapeshellcmd($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($tmpIn),
            escapeshellarg($tmpOut),
            escapeshellarg($movesJsonPath)
        );
        exec($cmd, $output, $exitCode);
        @unlink($tmpIn);
        @unlink($movesJsonPath);

        if ($exitCode !== 0 || !file_exists($tmpOut)) {
            @unlink($tmpOut);
            return response()->json([
                'error' => 'Move failed',
                'stdout' => implode("\n", $output),
                'exit' => $exitCode,
            ], 500);
        }

        $bytes = file_get_contents($tmpOut);
        @unlink($tmpOut);
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="moved.pdf"',
        ]);
    }

    public function editPdfjsReflowText(Request $request, Document $document)
    {
        $isMultipart = $request->isJson() === false && $request->hasFile('pdf');
        if ($isMultipart) {
            $raw = $request->input('reflows');
            if (is_string($raw)) $raw = json_decode($raw, true);
            if (!is_array($raw) || count($raw) === 0) {
                return response()->json(['error' => 'reflows[] missing or invalid'], 422);
            }
            $request->merge(['reflows' => $raw]);
        }

        $validated = $request->validate([
            'reflows' => ['required', 'array', 'min:1'],
            'reflows.*.page' => ['required', 'integer', 'min:0'],
            'reflows.*.original_text' => ['nullable', 'string'],
            'reflows.*.source_bbox' => ['required', 'array'],
            'reflows.*.source_bbox.x' => ['required', 'numeric'],
            'reflows.*.source_bbox.y' => ['required', 'numeric'],
            'reflows.*.source_bbox.w' => ['required', 'numeric'],
            'reflows.*.source_bbox.h' => ['required', 'numeric'],
            'reflows.*.new_bbox' => ['required', 'array'],
            'reflows.*.new_bbox.x' => ['required', 'numeric'],
            'reflows.*.new_bbox.y' => ['required', 'numeric'],
            'reflows.*.new_bbox.w' => ['required', 'numeric'],
            'reflows.*.new_bbox.h' => ['required', 'numeric'],
            'reflows.*.lock_rects' => ['nullable', 'array'],
            'reflows.*.lock_rects.*' => ['array'],
            'reflows.*.lock_rects.*.x' => ['required', 'numeric'],
            'reflows.*.lock_rects.*.y' => ['required', 'numeric'],
            'reflows.*.lock_rects.*.w' => ['required', 'numeric'],
            'reflows.*.lock_rects.*.h' => ['required', 'numeric'],
            'reflows.*.lines' => ['nullable', 'array'],
            'reflows.*.lines.*' => ['string'],
            'reflows.*.font_size_pts' => ['nullable', 'numeric'],
        ]);

        $tmpIn = tempnam(sys_get_temp_dir(), 'editpdfjs_reflowin_') . '.pdf';
        if ($isMultipart) {
            $upload = $request->file('pdf');
            if (!$upload->isValid()) {
                @unlink($tmpIn);
                return response()->json(['error' => 'Invalid uploaded PDF'], 422);
            }
            move_uploaded_file($upload->getRealPath(), $tmpIn);
        } else {
            if (!$document->path || !Storage::exists($document->path)) {
                @unlink($tmpIn);
                return response()->json(['error' => 'Current PDF missing'], 404);
            }
            copy(Storage::path($document->path), $tmpIn);
        }

        $tmpOut = tempnam(sys_get_temp_dir(), 'editpdfjs_reflowout_') . '.pdf';
        $jsonPath = tempnam(sys_get_temp_dir(), 'editpdfjs_reflows_') . '.json';
        file_put_contents($jsonPath, json_encode($validated['reflows']));

        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('pikepdf');
        $script = base_path('python/pdf-editor/move_text_inplace.py');

        $cmd = sprintf(
            '%s %s --in %s --out %s --reflows %s 2>&1',
            escapeshellcmd($pythonBinary),
            escapeshellarg($script),
            escapeshellarg($tmpIn),
            escapeshellarg($tmpOut),
            escapeshellarg($jsonPath)
        );
        exec($cmd, $output, $exitCode);
        @unlink($tmpIn);
        @unlink($jsonPath);

        if ($exitCode !== 0 || !file_exists($tmpOut)) {
            @unlink($tmpOut);
            return response()->json([
                'error' => 'Reflow failed',
                'stdout' => implode("\n", $output),
                'exit' => $exitCode,
            ], 500);
        }

        $bytes = file_get_contents($tmpOut);
        @unlink($tmpOut);
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="reflowed.pdf"',
        ]);
    }

    public function edit2(Document $document)
    {
        return view('documents.edit2', [
            'document' => $document,
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

        $document = Document::query()->findOrFail($validated['document_id']);
        $this->authorizeDocumentAccess($request, $document);

        $aiDocument = \App\Models\AiDocument::create([
            'document_id' => $document->id,
            'session_id' => Str::uuid(),
            'email' => $this->resolveEditorEmail(null),
        ]);

        return redirect()->to(route('documents.ai', ['document' => $aiDocument->id]));
    }


    public function guided(Document $document)
    {
        if ($document->mode !== 'guided') {
            return redirect()->route('documents.editPdfjs', $document);
        }

        $storedFormData = $document->form_data;
        if (is_string($storedFormData)) {
            $decoded = json_decode($storedFormData, true);
            $storedFormData = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($storedFormData)) {
            $storedFormData = [];
        }
        $storedTemplateType = trim((string) ($storedFormData['template_type'] ?? ''));
        $storedTemplateSlug = trim((string) ($storedFormData['template_slug'] ?? ''));
        if ($document->mode === 'guided' && ($document->template_type === null || $document->template_slug === null) && $storedTemplateType !== '' && $storedTemplateSlug !== '') {
            $document->forceFill([
                'template_type' => $document->template_type ?: $storedTemplateType,
                'template_slug' => $document->template_slug ?: $storedTemplateSlug,
            ])->save();
            $document->refresh();
        }

        if (
            $document->mode === 'guided'
            && $document->template_type === 'invoice'
            && ($document->template_slug === null || $document->template_slug === 'default')
        ) {
            $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
            $storedFull = Storage::path($document->path);
            $fieldCount = $this->ensurePdfAcroFormMaterialized($document, $storedFull, $pythonBinary);

            if ($fieldCount === 0 && is_file($storedFull)) {
                $formData = $document->form_data;
                if (is_string($formData)) {
                    $decoded = json_decode($formData, true);
                    $formData = is_array($decoded) ? $decoded : [];
                }
                if (!is_array($formData)) {
                    $formData = [];
                }

                $template = GuidedTemplate::where('type', 'invoice')
                    ->where('slug', 'default')
                    ->where('is_active', true)
                    ->first();
                $defaults = is_array($template?->defaults) ? $template->defaults : [];
                $payloadData = array_merge($defaults, $formData, ['style' => 'default']);
                $tmpPayload = tempnam(sys_get_temp_dir(), 'inv_upgrade_');
                file_put_contents($tmpPayload, json_encode($payloadData, JSON_UNESCAPED_UNICODE));

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

                if ($exitCode === 0 && is_file($storedFull)) {
                    $document->update([
                        'size_bytes' => filesize($storedFull),
                        'form_data' => $payloadData,
                    ]);
                    $this->refreshDocumentPreviewSnapshot($document);
                    $this->materializePdfAcroFormFields($document, $storedFull, $pythonBinary);
                } else {
                    Log::warning('Failed to upgrade guided invoice to AcroForm fields', [
                        'document_id' => $document->id,
                        'exit_code' => $exitCode,
                        'output' => implode("\n", $output),
                    ]);
                }
            }
        }

        // Guided templates now open in the PDF.js editor (the ?pdfjs=1 editor)
        // with a guided, text + signature focused toolbar. The generated
        // template PDF already contains the blank fields; turning on edit mode
        // by default lets the user click straight into them and type.
        return view('documents.edit-new-pdfjs', [
            'document' => $document,
            'guided' => true,
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

    public function file(Request $request, Document $document)
    {
        $this->requireDocumentPdfUnlock($request, $document);
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

    public function originalFile(Request $request, Document $document)
    {
        $this->requireDocumentPdfUnlock($request, $document);
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
                $this->resolvePdfStateOwnership($document),
                'saved'
            );
        }

        $this->refreshDocumentPreviewSnapshot($document);

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

            $pdfStateOwnership = $this->resolvePdfStateOwnership($document);
            $annotationCleanupQuery = PdfState::query()
                ->where('document_id', $document->id);

            $this->applyPdfStateOwnershipScope(
                $annotationCleanupQuery,
                $pdfStateOwnership['user_id'] ?? null,
                $pdfStateOwnership['admin_id'] ?? null,
                $request->session()->getId(),
            );

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

            $this->refreshDocumentPreviewSnapshot($document);

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

        $documents = $this->applyAccessibleDocumentScope($request, Document::query())
            ->whereIn('id', $validated['ids'])
            ->get();
        if ($documents->count() !== count($validated['ids'])) {
            abort(404);
        }
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
            $extractionJson = $this->resolveRedrawExtractionJson($document, $userEmail, $sessionId);

            if (!is_string($extractionJson)) {
                if (file_exists($editsFile)) @unlink($editsFile);
                return response()->json([
                    'success' => false,
                    'message' => 'No extraction data found. Please reload the document and try again.',
                ], 500);
            }

            $extractionFile = $makeTempFile('extraction_');
            if (@file_put_contents($extractionFile, $extractionJson) === false) {
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
        $this->refreshDocumentPreviewSnapshot($document);

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

        $this->refreshDocumentPreviewSnapshot($document);

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

    public function cleanPdf(Request $request, Document $document)
    {
        $this->requireDocumentPdfUnlock($request, $document);
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
     * Bake all unedited promoted annotations into the clean base PDF using the
     * exact writer + normalization pipeline the editor itself uses. /edit-new
     * loads this as the base so the canvas does not need to redraw promoted
     * source spans, eliminating the overprint/duplicate-text class of bugs.
     *
     * buildEditNewComparisonAnnotations() keeps its name from the retired
     * /admin/pdf-comparison page, but this is the live editor path and is the
     * only caller left.
     */
    public function bakedPdf(Request $request, Document $document)
    {
        $this->requireDocumentPdfUnlock($request, $document);
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

        $sourcePdfPath = Storage::path($document->path);
        if ($document->original_backup_path && Storage::exists($document->original_backup_path)) {
            $candidate = Storage::path($document->original_backup_path);
            if (is_file($candidate)) {
                $sourcePdfPath = $candidate;
            }
        }
        if (!is_file($sourcePdfPath)) {
            return response()->json(['error' => 'Source PDF not found'], 404);
        }

        $bakedPath = Storage::path('temp/baked_' . $document->id . '.pdf');
        $bakedDir = dirname($bakedPath);
        if (!is_dir($bakedDir)) {
            @mkdir($bakedDir, 0775, true);
        }

        // Cache: rebuild whenever clean PDF or extraction is newer than baked.
        $cleanMtime = (int) @filemtime($cleanPath);
        $latestExtractionAt = (int) optional(
            \App\Models\PdfExtractionFitz::where('document_id', $document->id)
                ->orderByDesc('updated_at')
                ->value('updated_at')
        )?->timestamp;
        $latestStateAt = (int) optional(
            \App\Models\PdfState::where('document_id', $document->id)
                ->orderByDesc('updated_at')
                ->value('updated_at')
        )?->timestamp;
        $needsRebuild = !is_file($bakedPath)
            || @filemtime($bakedPath) < max($cleanMtime, $latestExtractionAt, $latestStateAt);

        if ($needsRebuild) {
            $annotations = app(PdfTestController::class)
                ->buildEditNewComparisonAnnotations($request, $document, $sourcePdfPath);

            $tempDir = storage_path('app/temp');
            if (!is_dir($tempDir)) {
                @mkdir($tempDir, 0775, true);
            }
            $annotationsFile = $tempDir . '/baked_anns_' . $document->id . '_' . Str::uuid() . '.json';
            $annotationsJson = json_encode($annotations, JSON_INVALID_UTF8_SUBSTITUTE);
            if ($annotationsJson === false || @file_put_contents($annotationsFile, $annotationsJson) === false) {
                return response()->json(['error' => 'Failed to prepare annotations'], 500);
            }

            $script = base_path('python/pdf-editor/bake_edit_new_base.py');
            $generatedPath = $tempDir . '/baked_pdf_' . $document->id . '_' . Str::uuid() . '.pdf';
            $command = sprintf(
                '%s %s %s %s %s %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($script),
                escapeshellarg($cleanPath),
                escapeshellarg($sourcePdfPath),
                escapeshellarg($annotationsFile),
                escapeshellarg($generatedPath)
            );
            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);
            @unlink($annotationsFile);

            if ($exitCode !== 0 || !is_file($generatedPath)) {
                \Log::warning('Failed to bake edit-new base PDF', [
                    'document_id' => $document->id,
                    'exit_code' => $exitCode,
                    'output' => implode("\n", $output),
                ]);
                return response()->json(['error' => 'Failed to bake PDF'], 500);
            }

            if (!@rename($generatedPath, $bakedPath)) {
                @copy($generatedPath, $bakedPath);
                @unlink($generatedPath);
            }
        }

        return response()->file($bakedPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="baked.pdf"',
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
        $pdfPath = Storage::path($document->path);
        if (!file_exists($pdfPath)) {
            return response()->json(['error' => 'PDF not found'], 404);
        }

        $embeddedFontsPath = storage_path("app/temp/embedded_fonts_{$document->id}.json");
        $embeddedFonts = [];
        $hasValidEmbeddedFontsCache = false;
        if (file_exists($embeddedFontsPath)) {
            $decoded = json_decode((string) file_get_contents($embeddedFontsPath), true);
            if (is_array($decoded)) {
                $embeddedFonts = $decoded;
                // An empty array is a valid result for a PDF with no embedded
                // fonts. Treating it as a miss launched Python on every open.
                $hasValidEmbeddedFontsCache = true;
            }
        }

        if (!$hasValidEmbeddedFontsCache) {
            $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

            if (!is_dir(dirname($embeddedFontsPath))) {
                mkdir(dirname($embeddedFontsPath), 0755, true);
            }

            $command = sprintf(
                '%s -c %s 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($this->buildEmbeddedFontsExtractionScript(
                    $pdfPath,
                    (int) $document->id,
                    $embeddedFontsPath,
                ))
            );
            $output = shell_exec($command);
            if (file_exists($embeddedFontsPath)) {
                $decoded = json_decode((string) file_get_contents($embeddedFontsPath), true);
                $embeddedFonts = is_array($decoded) ? $decoded : [];
            }

            if (empty($embeddedFonts)) {
                \Log::warning('No embedded fonts extracted for document font picker', [
                    'document_id' => $document->id,
                    'output' => $output,
                ]);
            }
        }

        $fonts = collect($embeddedFonts)
            ->filter(static fn ($font) => is_array($font) && !empty($font['file_path']))
            ->map(static function (array $font, string $key): array {
                $cleanName = trim((string) ($font['clean_name'] ?? $key));
                return [
                    'name' => $cleanName,
                    'clean_name' => $cleanName,
                    'pdf_font_name' => (string) ($font['pdf_font_name'] ?? ''),
                    'family' => (string) ($font['family'] ?? $cleanName),
                    'file_path' => (string) ($font['file_path'] ?? ''),
                    'file_ext' => (string) ($font['file_ext'] ?? ''),
                    'css_weight' => (string) ($font['css_weight'] ?? '400'),
                    'css_style' => (string) ($font['css_style'] ?? 'normal'),
                    'css_stretch' => (string) ($font['css_stretch'] ?? 'normal'),
                    'xref' => $font['xref'] ?? null,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'fonts' => $fonts,
            'embedded_fonts' => $embeddedFonts,
        ]);
    }

    protected function buildEmbeddedFontsExtractionScript(
        string $pdfPath,
        int $documentId,
        string $embeddedFontsPath
    ): string {
        $json = static fn (string $value): string => json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );

        return 'import sys, json; '
            . 'sys.path.insert(0, '.$json(base_path('python/pdf-editor')).'); '
            . 'from extract_pdf_pymupdf import extract_embedded_fonts; '
            . 'fonts = extract_embedded_fonts('.$json($pdfPath).', '.$documentId.'); '
            . 'open('.$json($embeddedFontsPath).', "w", encoding="utf-8").write(json.dumps(fonts))';
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
                    $deletedQuery = PdfState::where('document_id', $document->id)
                        ->where('page_number', $deletedPage);
                    $this->applyPdfStateOwnershipScope(
                        $deletedQuery,
                        $this->resolvePdfStateUserId($document),
                        $this->resolvePdfStateAdminId($document),
                        (string) $sessionId
                    );
                    $deletedCount = $deletedQuery->delete();
                    
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

    public function mergePdfs(Request $request, Document $document, PdfDocumentMergeService $mergeService)
    {
        if ($document->mode === 'guided') {
            return response()->json([
                'success' => false,
                'message' => 'PDF merging is not available for guided documents.',
            ], 422);
        }

        $order = json_decode((string) $request->input('order', ''), true);
        if (!is_array($order)) {
            $order = [];
        }
        $request->merge(['merge_order' => array_values($order)]);

        $maxFiles = max(1, (int) config('pdf_editor.merge.max_files', 10));
        $maxFileKb = max(1, (int) config('pdf_editor.merge.max_file_kb', 20480));
        $validated = $request->validate([
            'pdfs' => ['required', 'array', 'min:1', "max:{$maxFiles}"],
            'pdfs.*' => ['required', 'file', 'mimes:pdf', "max:{$maxFileKb}"],
            'merge_order' => ['required', 'array'],
            'merge_order.*' => ['required', 'string', 'max:80'],
            'session_id' => ['nullable', 'string', 'max:255'],
        ]);

        $expectedOrderIds = array_merge(['current'], array_keys($validated['pdfs']));
        $submittedOrderIds = array_values($validated['merge_order']);
        if (count($submittedOrderIds) !== count($expectedOrderIds)
            || count($submittedOrderIds) !== count(array_unique($submittedOrderIds))
            || count(array_intersect($expectedOrderIds, $submittedOrderIds)) !== count($expectedOrderIds)) {
            return response()->json([
                'success' => false,
                'message' => 'The PDF document order is invalid.',
            ], 422);
        }

        if ($response = $this->consumeMonthlyActionQuota($request)) {
            return $response;
        }

        try {
            $result = $mergeService->merge(
                $document,
                $validated['pdfs'],
                $validated['merge_order'],
                $this->resolveEditorEmail(),
                $validated['session_id'] ?? $request->session()->getId(),
            );

            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => 'Merge PDFs',
                    'category' => 'pdf_merge',
                    'details' => [
                        'input_count' => count($validated['pdfs']) + 1,
                        'total_pages' => $result['total_pages'] ?? null,
                    ],
                    'document_id' => $document->id,
                    'status' => 'success',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json(array_merge($result, [
                'success' => true,
                'message' => 'PDFs merged successfully.',
                'pdf_url' => route('documents.file', $document) . '?v=' . now()->getTimestampMs(),
            ]));
        } catch (PdfDocumentMergeConflictException $error) {
            return response()->json(['success' => false, 'message' => $error->getMessage()], 409);
        } catch (\RuntimeException $error) {
            Log::warning('PDF merge rejected', [
                'document_id' => $document->id,
                'error' => $error->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            Log::error('PDF merge failed', [
                'document_id' => $document->id,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The PDF files could not be merged. Please try again.',
            ], 500);
        }
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
        
        // Overlay editor is retired; keep the request field for compatibility
        // but always capture the unified edit screen.
        $url = "{$baseUrl}/documents/{$document->id}/edit";
        
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
        $pdfStateOwnership = $this->pdfStateOwnershipPayload($document, $sessionId, $userEmail);
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

            $existingAnnotation = $this->findExistingPdfStateRecordForAnnotation(
                $document,
                $sessionId,
                $annotation
            );
            
            if ($existingAnnotation) {
                // Update existing annotation
                $existingAnnotation->update([
                    'annotation_data' => $annotation,
                    'user_id' => $pdfStateOwnership['user_id'],
                    'admin_id' => $pdfStateOwnership['admin_id'],
                    'user_email' => $pdfStateOwnership['user_email'],
                    'session_id' => $sessionId,
                    'page_number' => $annotation['pageIndex'] ?? null,
                    'state' => 'not_saved',
                ]);

                $enriched = $this->annotationAssets()->enrichForClient($annotation);
                $enriched['db_id'] = $existingAnnotation->id;

                return response()->json([
                    'success' => true,
                    'message' => 'Annotation updated',
                    'updated' => true,
                    'annotation' => $enriched,
                ]);
            } else {
                // Create new annotation
                $newState = PdfState::create([
                    'document_id' => $document->id,
                    'page_number' => $annotation['pageIndex'] ?? null,
                    'annotation_data' => $annotation,
                    'state' => 'not_saved',
                    ...$pdfStateOwnership,
                ]);

                $enriched = $this->annotationAssets()->enrichForClient($annotation);
                $enriched['db_id'] = $newState->id;

                return response()->json([
                    'success' => true,
                    'message' => 'Annotation created',
                    'created' => true,
                    'annotation' => $enriched,
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

            $existingAnnotation = $this->findExistingPdfStateRecordForAnnotation(
                $document,
                $sessionId,
                $annotation
            );

            if ($existingAnnotation) {
                $existingAnnotation->update([
                    'annotation_data' => $annotation,
                    'user_id' => $pdfStateOwnership['user_id'],
                    'admin_id' => $pdfStateOwnership['admin_id'],
                    'user_email' => $pdfStateOwnership['user_email'],
                    'session_id' => $sessionId,
                    'page_number' => $annotation['pageIndex'] ?? null,
                    'state' => $targetState,
                ]);
                $savedCount++;
                $updatedCount++;
                \Log::info("Updated annotation", ['id' => $annotationId, 'state' => $targetState]);
                continue;
            }
            
            // Create new annotation if not found
            PdfState::create([
                'document_id' => $document->id,
                'page_number' => $annotation['pageIndex'] ?? null,
                'annotation_data' => $annotation,
                'state' => $targetState,
                ...$pdfStateOwnership,
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
        $ownership = $this->resolvePdfStateOwnership($document);
        $buildRecordsQuery = function (?string $sessionId = null) use ($document, $excludeMaterialized, $ownership) {
            $query = PdfState::query()
                ->where('document_id', $document->id)
                ->where('state', '!=', 'deleted')
                ->where('state', '!=', 'extracted');

            if ($sessionId !== null && $sessionId !== '') {
                $query->where('session_id', $sessionId);
            }

            $this->applyPdfStateOwnershipScope(
                $query,
                $ownership['user_id'] ?? null,
                $ownership['admin_id'] ?? null,
                $sessionId ?? '',
            );

            if ($excludeMaterialized) {
                $query->where('state', '!=', 'materialized');
            }

            return $query;
        };

        $resolvedSessionId = '';
        $records = collect();

        if ($requestedSessionId !== '') {
            $records = $buildRecordsQuery($requestedSessionId)
                ->orderBy('page_number')
                ->orderBy('updated_at')
                ->get();
            if ($records->isNotEmpty()) {
                return [$requestedSessionId, $records];
            }
        }

        $latestSessionId = (string) ($buildRecordsQuery()
            ->orderByDesc('updated_at')
            ->value('session_id') ?? '');

        if ($latestSessionId !== '') {
            $records = $buildRecordsQuery($latestSessionId)
                ->orderBy('page_number')
                ->orderBy('updated_at')
                ->get();
            $resolvedSessionId = $latestSessionId;
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
        [$resolvedSessionId, $records] = $this->resolveSavedAnnotationRecords($document, $requestedSessionId);
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
            $annotation['db_id'] = $record->id;
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
            'session_ids' => 'nullable|array',
            'session_ids.*' => 'string',
        ]);

        $requestedSessionId = is_string($validated['session_id'] ?? null)
            ? trim((string) $validated['session_id'])
            : '';
        $requestedSessionIds = collect($validated['session_ids'] ?? [])
            ->map(static fn ($value) => is_string($value) ? trim($value) : '')
            ->filter(static fn ($value) => $value !== '')
            ->prepend($requestedSessionId)
            ->unique()
            ->values();
        $editorOwnership = $this->resolvePdfStateOwnership();

        $query = PdfState::query()
            ->with(['document:id,original_name,path'])
            ->select(['id', 'document_id', 'user_id', 'admin_id', 'user_email', 'session_id', 'state', 'updated_at'])
            ->where('state', '!=', 'deleted')
            ->where('state', '!=', 'extracted');

        if (($editorOwnership['user_id'] ?? null) !== null || ($editorOwnership['admin_id'] ?? null) !== null) {
            $this->applyPdfStateOwnershipScope(
                $query,
                $editorOwnership['user_id'] ?? null,
                $editorOwnership['admin_id'] ?? null,
            );
        } elseif ($requestedSessionIds->isNotEmpty()) {
            $query->whereIn('session_id', $requestedSessionIds->all());
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

        $savedEntries = $records
            ->groupBy(static fn (PdfState $record): string => (
                $record->document_id . "\0" . (string) $record->session_id
            ))
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
                    'edit_url' => $this->resolveDocumentEditorUrl($document),
                    'load_url' => route('documents.loadSavedPdf', $document),
                    'delete_url' => route('documents.deleteSavedPdfOption', $document),
                ];
            })
            ->filter()
            ->values();
        $savedEntriesByDocumentId = $savedEntries->groupBy('document_id');

        $documentQuery = Document::query()
            ->select(['id', 'user_id', 'admin_id', 'original_name', 'path', 'updated_at', 'mode'])
            ->where(function ($query) {
                $query->whereNull('mode')
                    ->orWhere('mode', '!=', 'regression');
            });
        $this->applyAccessibleDocumentScope($request, $documentQuery);

        $documents = $documentQuery
            ->orderByDesc('updated_at')
            ->get()
            ->keyBy('id');

        $savedEntries->each(function (array $entry) use ($documents, $request) {
            $documentId = (int) ($entry['document_id'] ?? 0);
            if (!$documents->has($documentId) && !empty($entry['document_id'])) {
                $document = Document::query()
                    ->select(['id', 'user_id', 'admin_id', 'original_name', 'path', 'updated_at', 'mode'])
                    ->find($entry['document_id']);
                if ($document && $this->canAccessDocument($request, $document)) {
                    $documents->put($document->id, $document);
                }
            }
        });

        $pdfs = $documents
            ->flatMap(function (Document $document) use ($savedEntriesByDocumentId) {
                $documentEntries = $savedEntriesByDocumentId->get($document->id, collect());
                $documentUpdatedAt = optional($document->updated_at)?->toIso8601String();

                if ($documentEntries->isEmpty()) {
                    $documentEntries = collect([null]);
                }

                return $documentEntries->map(function ($savedEntry) use ($document, $documentUpdatedAt) {
                    $savedUpdatedAt = $savedEntry['updated_at'] ?? null;

                    return [
                        'document_id' => $document->id,
                        'pdf_name' => $document->original_name ?: basename((string) $document->path),
                        'session_id' => $savedEntry['session_id'] ?? null,
                        'annotation_count' => $savedEntry['annotation_count'] ?? 0,
                        'has_saved_state' => (bool) $savedEntry,
                        'updated_at' => $savedUpdatedAt ?: $documentUpdatedAt,
                        'edit_url' => $this->resolveDocumentEditorUrl($document),
                        'load_url' => route('documents.loadSavedPdf', $document),
                        'delete_url' => route('documents.deleteSavedPdfOption', $document),
                        '_sort_timestamp' => max(
                            optional($document->updated_at)?->getTimestamp() ?? 0,
                            $savedUpdatedAt ? (strtotime($savedUpdatedAt) ?: 0) : 0,
                        ),
                    ];
                });
            })
            ->sortByDesc(static fn (array $entry) => $entry['_sort_timestamp'] ?? 0)
            ->values()
            ->map(function (array $entry) {
                unset($entry['_sort_timestamp']);
                return $entry;
            })
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

        $editorOwnership = $this->resolvePdfStateOwnership($document);
        $deleteQuery = PdfState::query()
            ->where('document_id', $document->id)
            ->where('session_id', $sessionId);

        if (($editorOwnership['user_id'] ?? null) !== null) {
            $deleteQuery->where('user_id', $editorOwnership['user_id']);
        } elseif (($editorOwnership['admin_id'] ?? null) !== null) {
            $deleteQuery->where('admin_id', $editorOwnership['admin_id']);
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
            'edit_url' => $this->resolveDocumentEditorUrl($document),
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
            $pdfStateOwnership = $this->resolvePdfStateOwnership($document);
            $this->syncDeletedPromotedSourceKeysForSession(
                $document,
                $sessionId,
                $deletedPromotedSourceKeys
            );

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

                $existingRecordQuery = PdfState::query()
                    ->where('document_id', $document->id)
                    ->whereRaw("JSON_EXTRACT(annotation_data, '$.id') = ?", [$annotationId]);
                $this->applyPdfStateOwnershipScope(
                    $existingRecordQuery,
                    $pdfStateOwnership['user_id'],
                    $pdfStateOwnership['admin_id'],
                    $sessionId
                );
                $existingRecord = $existingRecordQuery->first();

                if ($existingRecord) {
                    $existingRecord->update([
                        'annotation_data' => $annotation,
                        'session_id' => $sessionId,
                        'user_id' => $pdfStateOwnership['user_id'],
                        'admin_id' => $pdfStateOwnership['admin_id'],
                        'user_email' => ($pdfStateOwnership['user_id'] !== null || $pdfStateOwnership['admin_id'] !== null)
                            ? null
                            : $existingRecord->user_email,
                        'page_number' => $annotation['pageIndex'] ?? null,
                        'state' => 'saved',
                    ]);
                    continue;
                }

                PdfState::create([
                    'document_id' => $document->id,
                    'page_number' => $annotation['pageIndex'] ?? null,
                    'annotation_data' => $annotation,
                    'state' => 'saved',
                    ...$this->pdfStateOwnershipPayload($document, $sessionId),
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

    /**
     * Overwrite a single saved annotation's text and re-render the PDF using
     * the same writer that the pdfjs=1 / pdfjs visible export path uses
     * (apply_annotations_direct_new.py). Looks up the annotation by id in
     * pdf_state for the given document, replaces its text, redacts the source
     * glyphs, draws the new overlay, and writes the result back to the
     * document file. Persists the updated annotation_data on the pdf_state row.
     *
     * Expected JSON payload:
     * {
     *   "document_id": 4161,
     *   "annotation_id": "pdfjs_4161_1_1:18",
     *   "text": "New text to stamp in place of the original",
     *   "original_text": "optional exact original text (data-original-text) — used as a tiebreaker when the runtime annotation_id (e.g. pdfjs_*_*_source:*) does not directly match a persisted row",
     *   "session_id": "optional-editor-session-id",
     *   "generate_new": 1 // optional — when truthy, file_url/view_url include a cache-busting ?v=<ts>
     * }
     */
    public function overwriteAnnotationText(Request $request)
    {
        $validated = $request->validate([
            'document_id'   => ['required', 'integer'],
            'annotation_id' => ['required', 'string'],
            'text'          => ['present', 'string'],
            'original_text' => ['nullable', 'string'],
            'session_id'    => ['nullable', 'string'],
            'generate_new'  => ['nullable'],
        ]);

        $document = Document::find((int) $validated['document_id']);
        if (!$document) {
            return response()->json([
                'success'     => false,
                'message'     => 'Document not found.',
                'document_id' => (int) $validated['document_id'],
            ], 404);
        }

        $annotationId = trim((string) $validated['annotation_id']);
        $newText      = (string) $validated['text'];
        $sessionId    = is_string($validated['session_id'] ?? null) ? trim((string) $validated['session_id']) : '';
        $originalTextHint = is_string($validated['original_text'] ?? null) ? (string) $validated['original_text'] : '';

        if ($annotationId === '') {
            return response()->json(['success' => false, 'message' => 'annotation_id is required.'], 422);
        }

        // Locate the saved annotation by its id within this document's pdf_state rows.
        // The editor uses several runtime id shapes that do not always map
        // directly to the persisted id:
        //   * Persisted promoted rows:           promoted_{page1based}_{blockNum}
        //   * Editor overlay id:                 pdfjs_{docId}_{pageIndex0based}_{uid}
        //   * Editor source-editor box id:       pdfjs_{docId}_{pageIndex0based}_source:{pageIndex}:{spanIndex}
        // We collect a few candidate persisted ids, then fall back to a
        // page-scoped originalText/pdfjsSourceText/text match (using the
        // optional original_text hint or the new text itself as a tiebreaker)
        // because source-editor boxes are matched to promoted rows by
        // geometry+text in the editor, not by id.
        $candidateIds = [$annotationId];
        $pageHint = null;
        if (preg_match('/^pdfjs_\d+_(\d+)_(.+)$/', $annotationId, $m)) {
            $pageHint = (int) $m[1];                              // zero-based page index
            $anchor   = $m[2];
            // strip a leading "source:" so source-editor anchors like
            // "source:0:15" become "0:15" before we hunt for a trailing uid
            $anchorForUid = preg_replace('/^source:/', '', $anchor);
            if (preg_match('/(\d+)\s*$/', $anchorForUid, $u)) {
                $uid = (int) $u[1];
                $candidateIds[] = 'promoted_' . ($pageHint + 1) . '_' . $uid;
            }
        }
        $candidateIds = array_values(array_unique($candidateIds));

        $recordQuery = PdfState::query()
            ->where('document_id', $document->id)
            ->where(function ($q) use ($candidateIds) {
                foreach ($candidateIds as $cid) {
                    $q->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.id')) = ?", [$cid]);
                }
            })
            ->where('state', '!=', 'deleted')
            ->orderByDesc('updated_at');
        $record = null;
        if ($sessionId !== '') {
            // Prefer a row from the caller's session if one exists, but fall
            // back to any matching row so the session_id hint is non-fatal.
            $record = (clone $recordQuery)->where('session_id', $sessionId)->first();
        }
        if (!$record) {
            $record = $recordQuery->first();
        }

        // Fallback: page-scoped text match. Used for source-editor boxes
        // whose runtime id (e.g. "pdfjs_4153_0_source:0:15") is not derived
        // from the persisted "promoted_{page}_{blockNum}" id and therefore
        // can't be reverse-engineered. Callers should pass `original_text`
        // (the box's data-original-text) for an exact match.
        $textCandidates = [];
        $normalize = static function ($v) {
            $s = (string) ($v ?? '');
            $s = preg_replace('/\s+/u', ' ', $s);
            return trim($s);
        };
        if ($originalTextHint !== '') $textCandidates[] = $normalize($originalTextHint);
        if (!$record && $pageHint !== null && !empty($textCandidates)) {
            $pageRows = PdfState::query()
                ->where('document_id', $document->id)
                ->where('state', '!=', 'deleted')
                ->orderByDesc('updated_at')
                ->get(['id', 'annotation_data']);
            foreach ($pageRows as $row) {
                $d = is_array($row->annotation_data) ? $row->annotation_data : null;
                if (!$d) continue;
                if ((int) ($d['pageIndex'] ?? -1) !== $pageHint) continue;
                $rowTexts = array_filter([
                    $normalize($d['originalText'] ?? null),
                    $normalize($d['pdfjsSourceText'] ?? null),
                    $normalize($d['text'] ?? null),
                ], static fn ($v) => $v !== '');
                foreach ($textCandidates as $cand) {
                    if ($cand !== '' && in_array($cand, $rowTexts, true)) {
                        $record = $row;
                        break 2;
                    }
                }
            }
        }

        if (!$record || !is_array($record->annotation_data)) {
            return response()->json([
                'success'        => false,
                'message'        => 'Annotation not found for this document. Pass `original_text` (the box\'s data-original-text) if the runtime annotation_id is a source-editor anchor (pdfjs_*_*_source:*).',
                'document_id'    => $document->id,
                'annotation_id'  => $annotationId,
                'tried_ids'      => $candidateIds,
                'page_hint'      => $pageHint,
                'original_text'  => $originalTextHint,
            ], 404);
        }

        $annotation = $record->annotation_data;
        if (($annotation['type'] ?? null) !== 'text') {
            return response()->json([
                'success' => false,
                'message' => 'Only text annotations can be overwritten via this endpoint.',
            ], 422);
        }

        // Capture the original text as the source-text baseline so the writer's
        // redaction pass knows exactly which glyphs to erase from the canvas.
        // pdfjsSourceText is already populated for promoted/source-fidelity
        // boxes; for plain overlay text fall back to the previous `text` value.
        $previousText  = (string) ($annotation['text'] ?? '');
        $sourceText    = (string) ($annotation['pdfjsSourceText']
            ?? $annotation['originalText']
            ?? $previousText);

        $annotation['text']                  = $newText;
        if (!isset($annotation['originalText']) || $annotation['originalText'] === '') {
            $annotation['originalText']      = $sourceText;
        }
        if (!isset($annotation['pdfjsSourceText']) || $annotation['pdfjsSourceText'] === '') {
            $annotation['pdfjsSourceText']   = $sourceText;
        }
        // Force the writer to mask the source and stamp the new text. These
        // flags mirror what the editor sets when the user edits a source-backed
        // box (see shouldMaskSourceForAnnotation in resources/js/edit-new-pdfjs/main.js).
        //
        // Intentionally do NOT set `styleDirty` here: this endpoint changes
        // only the text, not typography. Setting styleDirty short-circuits
        // resolve_pdfjs_visible_overlay_typography() in the writer, which is
        // what matches the replacement glyphs to the source PDF span's actual
        // font (e.g. ITCFranklinGothicStd-Dem). Without source resolution
        // the writer falls back to the annotation's stored fontFamily/weight
        // captured by extraction (lossy — generic family, weight 400), and
        // the re-stamped text renders in the wrong weight.
        $annotation['savedTextOverlay']      = true;
        // Tell resolve_embedded_font_entry() that this is a text-only edit
        // and the runtime-extracted source font (e.g. the full Demi face
        // bundled under /public/fonts/runtime-extracted/{id}/) should still
        // be used despite promotedDirty being set. Without this flag the
        // writer falls back to bundled Arimo-Regular and the re-stamped
        // heading looks visibly lighter than the original.
        $annotation['preserveSourceTypography'] = true;
        // For promoted (extracted-source) annotations the pdfjs writer
        // (apply_annotations_direct_new.py) only redacts + restamps when
        // promotedDirty is truthy — otherwise it short-circuits via
        // should_preserve_promoted_source_lines() and the edit is a no-op.
        if ($this->annotationFlagIsTruthy($annotation, 'promotedFromExtraction')
            || str_starts_with((string) ($annotation['id'] ?? ''), 'promoted_')) {
            $annotation['promotedDirty']     = true;
        }
        if (!isset($annotation['pdfjsEditorMode']) || $annotation['pdfjsEditorMode'] === '') {
            $annotation['pdfjsEditorMode']   = 'source';
        }
        // Rebuild source-mode rich text from sourceSpans even if stale
        // display-only richTextHtml was persisted earlier. The writer must
        // see separate runs before it resolves per-run glyph coverage.
        $annotation = $this->ensureRichTextHtmlForMultiStyleSource($annotation);
        // Keep the persisted id intact (caller may have passed the runtime form).
        $persistedAnnotationId = (string) ($annotation['id'] ?? '');
        $annotation['__documentId']          = $document->id;

        $pdfPath = Storage::path($document->path);
        if (!file_exists($pdfPath)) {
            return response()->json(['success' => false, 'message' => 'Document file not found.'], 404);
        }

        // Always run through the pdfjs visible-export writer
        // (apply_annotations_direct_new.py) — the same drawer pdfjs=1 uses
        // for saving. We deliberately skip filterAnnotationsForPdfjsVisibleExport
        // here because that filter is designed for bulk download where
        // promoted (extracted-source) rows must be left alone (their glyphs
        // already exist in the source PDF). For a targeted single-annotation
        // overwrite we WANT the writer to redact + restamp the source glyphs,
        // which apply_annotations_direct_new.py does natively when the
        // annotation carries pdfjsSourceText / sourceText baselines.
        $sourcePdfPath = $pdfPath;
        if ($document->original_backup_path && Storage::exists($document->original_backup_path)) {
            $originalBackupPath = Storage::path($document->original_backup_path);
            if (file_exists($originalBackupPath)) {
                $sourcePdfPath = $originalBackupPath;
            }
        }
        $fullAnnotations = [$annotation];
        $script = base_path('python/pdf-editor/apply_annotations_direct_new.py');

        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $tempPdfPath = $tempDir . '/overwrite_ann_' . $document->id . '_' . Str::uuid() . '.pdf';
        $annotationsFile = $tempDir . '/overwrite_ann_' . $document->id . '_' . uniqid('', true) . '.json';
        $backupPath = $tempDir . '/overwrite_ann_backup_' . $document->id . '_' . Str::uuid() . '.pdf';

        if (!@copy($sourcePdfPath, $tempPdfPath)) {
            return response()->json(['success' => false, 'message' => 'Failed to prepare PDF working copy.'], 500);
        }
        @copy($pdfPath, $backupPath);

        $payload = $this->prepareAnnotationsForPython($fullAnnotations);
        $payload = array_map(static function ($entry) use ($document) {
            if (is_array($entry)) {
                $entry['__documentId'] = $document->id;
            }
            return $entry;
        }, $payload);
        if (empty($payload)) {
            @unlink($tempPdfPath);
            @unlink($backupPath);
            return response()->json([
                'success' => false,
                'message' => 'Annotation payload became empty after prepare; nothing to apply.',
            ], 500);
        }

        $annotationsJson = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($annotationsJson === false || @file_put_contents($annotationsFile, $annotationsJson) === false) {
            @unlink($tempPdfPath);
            @unlink($backupPath);
            return response()->json(['success' => false, 'message' => 'Failed to write annotations payload.'], 500);
        }

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

        $stdout = implode("\n", $output);

        if ($returnCode !== 0) {
            @unlink($annotationsFile);
            @unlink($tempPdfPath);
            @unlink($backupPath);
            \Log::error('Overwrite annotation text failed', [
                'document_id'   => $document->id,
                'annotation_id' => $annotationId,
                'return_code'   => $returnCode,
                'output'        => $stdout,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to redact and stamp the new annotation text.',
                'error'   => $stdout,
            ], 500);
        }

        if (!@copy($tempPdfPath, $pdfPath)) {
            // Roll back to the pre-call PDF bytes.
            @copy($backupPath, $pdfPath);
            @unlink($annotationsFile);
            @unlink($tempPdfPath);
            @unlink($backupPath);
            return response()->json([
                'success' => false,
                'message' => 'Failed to write the redacted PDF back to the document file.',
            ], 500);
        }

        // Keep the annotations payload around for debugging if the env requests it.
        if (config('app.debug')) {
            \Log::info('Overwrite annotation text succeeded', [
                'document_id'   => $document->id,
                'annotation_id' => $annotationId,
                'persisted_id'  => $persistedAnnotationId,
                'pdf_state_id'  => $record->id,
                'annotations_in_payload' => count($payload),
                'source_pdf'    => $sourcePdfPath,
                'writer_output' => $stdout,
            ]);
        }
        @unlink($annotationsFile);
        @unlink($tempPdfPath);
        @unlink($backupPath);

        // Persist the annotation update on the pdf_state row so subsequent
        // editor loads see the new text. Strip the controller-only marker
        // and restore the original persisted id.
        $persistedAnnotation = $annotation;
        unset($persistedAnnotation['__documentId']);
        if ($persistedAnnotationId !== '') {
            $persistedAnnotation['id'] = $persistedAnnotationId;
        }
        $record->annotation_data = $persistedAnnotation;
        $record->state = 'saved';
        if ($sessionId !== '') {
            $record->session_id = $sessionId;
        }
        $record->save();

        $document->size_bytes = @filesize($pdfPath) ?: $document->size_bytes;
        $document->updated_at = now();
        $document->saveQuietly();

        // By default return stable URLs (no cache-buster) so callers can reuse
        // the same link across overwrites. Pass "generate_new": 1 in the
        // payload to get a fresh cache-busted URL that forces a reload.
        $generateNew = $request->boolean('generate_new');
        $bust = $generateNew ? ('?v=' . urlencode((string) now()->timestamp)) : '';

        return response()->json([
            'success'        => true,
            'message'        => 'Annotation text overwritten and PDF re-rendered.',
            'document_id'    => $document->id,
            'annotation_id'  => $annotationId,
            'previous_text'  => $previousText,
            'new_text'       => $newText,
            'pdf_state_id'   => $record->id,
            'session_id'     => $record->session_id,
            'file_url'       => route('documents.file', $document) . $bust,
            'view_url'       => route('documents.editPdfjs', $document) . $bust,
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
            $this->authorizeDocumentAccess($request, $baseDocument);

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
        $ownership = $this->resolvePdfStateOwnership($document);
        $annotationIds = array_values(array_filter(array_map(
            static fn ($value) => is_string($value) ? trim($value) : '',
            $validated['annotation_ids'] ?? []
        )));

        if (!empty($annotationIds)) {
            foreach ($annotationIds as $annotationId) {
                $updateQuery = PdfState::where('document_id', $document->id)
                    ->whereRaw("JSON_EXTRACT(annotation_data, '$.id') = ?", [$annotationId])
                    ->where('state', '!=', 'extracted');
                $this->applyPdfStateOwnershipScope($updateQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
                $updateQuery->update(['state' => 'saved']);
            }
        } else {
            $updateAllQuery = PdfState::where('document_id', $document->id)
                ->where('state', '!=', 'extracted');
            $this->applyPdfStateOwnershipScope($updateAllQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
            $updateAllQuery->update(['state' => 'saved']);
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
        $annotationIds = is_array($validated['annotation_ids']) ? $validated['annotation_ids'] : [];
        $explicitKeys = is_array($validated['deleted_promoted_source_keys'] ?? null)
            ? $validated['deleted_promoted_source_keys']
            : [];

        $deletedPromotedSourceKeys = $this->applyAnnotationDeletions(
            $document,
            $sessionId,
            $annotationIds,
            $explicitKeys
        );

        foreach ($deletedPromotedSourceKeys as $sourceKey) {
            $this->ensurePromotedSuppressionRecordForSession($document, $sessionId, $sourceKey);
        }

        return response()->json([
            'success' => true,
            'deleted' => count(array_filter($annotationIds, 'is_string')),
            'deleted_promoted_source_keys' => $deletedPromotedSourceKeys,
        ]);
    }

    public function saveAnnotationDebug(Request $request, Document $document)
    {
        $validated = $request->validate([
            'annotation_id' => 'required|string|max:255',
            'annotation' => 'nullable|array',
            'debug' => 'required|array',
            'debug.note' => 'nullable|string|max:50000',
            'debug.mask' => 'nullable|array',
            'debug.mask.pageIndex' => 'nullable|integer|min:0',
            'debug.mask.x' => 'nullable|numeric',
            'debug.mask.y' => 'nullable|numeric',
            'debug.mask.w' => 'nullable|numeric|min:0',
            'debug.mask.h' => 'nullable|numeric|min:0',
            'debug.images' => 'nullable|array|max:12',
            'debug.images.*.name' => 'nullable|string|max:255',
            'debug.images.*.type' => 'nullable|string|max:100',
            'debug.images.*.dataUrl' => 'nullable|string|max:5000000',
            'session_id' => 'nullable|string',
        ]);

        $sessionId = is_string($validated['session_id'] ?? null)
            ? trim((string) $validated['session_id'])
            : '';
        $annotationId = trim((string) $validated['annotation_id']);
        $annotation = is_array($validated['annotation'] ?? null) ? $validated['annotation'] : [];
        $annotation['id'] = trim((string) ($annotation['id'] ?? $annotationId)) ?: $annotationId;

        if (!isset($annotation['pageIndex'])) {
            $maskPage = $validated['debug']['mask']['pageIndex'] ?? null;
            $annotation['pageIndex'] = is_numeric($maskPage) ? (int) $maskPage : 0;
        }
        if (!isset($annotation['type'])) {
            $annotation['type'] = 'text';
        }

        $normalized = $this->normalizeAnnotationsForPersistence($document, [$annotation]);
        $annotation = $normalized[0] ?? $annotation;
        $debug = $this->normalizeAnnotationDebugPayload($validated['debug'], $annotationId);

        $record = $this->findPdfStateRecordForAnnotationDebug($document, $sessionId, $annotation);
        $debugColumnExists = Schema::hasColumn('pdf_state', 'annotation_debug');

        if ($record) {
            $annotationData = is_array($record->annotation_data) ? $record->annotation_data : [];
            $record->annotation_data = array_merge($annotationData, $annotation, ['_debug' => $debug]);
            $record->page_number = is_numeric($annotation['pageIndex'] ?? null) ? (int) $annotation['pageIndex'] : $record->page_number;
            if ($sessionId !== '') {
                $record->session_id = $sessionId;
            }
            if ($debugColumnExists) {
                $record->annotation_debug = $debug;
            }
            if ((string) $record->state === '') {
                $record->state = 'saved';
            }
            $record->save();
        } else {
            $payload = [
                'document_id' => $document->id,
                'page_number' => is_numeric($annotation['pageIndex'] ?? null) ? (int) $annotation['pageIndex'] : 0,
                'annotation_data' => array_merge($annotation, ['_debug' => $debug]),
                'state' => 'saved',
                ...$this->pdfStateOwnershipPayload($document, $sessionId),
            ];
            if ($debugColumnExists) {
                $payload['annotation_debug'] = $debug;
            }
            $record = PdfState::create($payload);
        }

        return response()->json([
            'success' => true,
            'message' => 'Annotation debug saved.',
            'annotation_id' => $annotationId,
            'db_id' => $record->id,
            'debug' => $debug,
        ]);
    }

    private function normalizeAnnotationDebugPayload(array $debug, string $annotationId): array
    {
        $mask = is_array($debug['mask'] ?? null) ? $debug['mask'] : [];
        $normalizedMask = [];
        foreach (['pageIndex', 'x', 'y', 'w', 'h'] as $key) {
            if (!array_key_exists($key, $mask) || !is_numeric($mask[$key])) {
                continue;
            }
            $value = $key === 'pageIndex' ? (int) $mask[$key] : round((float) $mask[$key], 4);
            if (($key === 'w' || $key === 'h') && $value < 0) {
                $value = 0;
            }
            $normalizedMask[$key] = $value;
        }

        $images = [];
        foreach (array_slice(is_array($debug['images'] ?? null) ? $debug['images'] : [], 0, 12) as $image) {
            if (!is_array($image)) {
                continue;
            }
            $dataUrl = trim((string) ($image['dataUrl'] ?? ''));
            if ($dataUrl === '' || !str_starts_with($dataUrl, 'data:image/')) {
                continue;
            }
            if (strlen($dataUrl) > 5000000) {
                continue;
            }
            $images[] = [
                'name' => Str::limit(trim((string) ($image['name'] ?? 'debug-image')), 255, ''),
                'type' => Str::limit(trim((string) ($image['type'] ?? 'image')), 100, ''),
                'dataUrl' => $dataUrl,
            ];
        }

        return [
            'annotation_id' => $annotationId,
            'note' => (string) ($debug['note'] ?? ''),
            'mask' => $normalizedMask,
            'images' => $images,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function findPdfStateRecordForAnnotationDebug(
        Document $document,
        string $sessionId,
        array $annotation
    ): ?PdfState {
        $record = $this->findExistingPdfStateRecordForAnnotation($document, $sessionId, $annotation);
        if ($record) {
            return $record;
        }

        $annotationId = trim((string) ($annotation['id'] ?? ''));
        $sourceKey = trim((string) ($annotation['promotedSourceKey'] ?? ''));
        $queryFor = function (string $jsonField, string $value) use ($document): ?PdfState {
            if ($value === '') {
                return null;
            }

            return PdfState::query()
                ->where('document_id', $document->id)
                ->where('state', '!=', 'deleted')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$." . $jsonField . "')) = ?", [$value])
                ->orderByDesc('updated_at')
                ->first();
        };

        return $queryFor('id', $annotationId) ?: $queryFor('promotedSourceKey', $sourceKey);
    }

    /**
     * Mark PdfState rows as deleted for the given annotation ids, plus bulk-mark
     * any rows whose promotedSourceKey matches the explicit list (or is auto-
     * discovered from a deleted promotedFromExtraction annotation). Returns the
     * sorted, unique list of promoted source keys that were affected.
     *
     * Suppression-record bookkeeping is the caller's responsibility (either via
     * ensurePromotedSuppressionRecordForSession or the
     * syncDeletedPromotedSourceKeysForSession path used by saveAnnotationState).
     */
    private function applyAnnotationDeletions(
        Document $document,
        string $sessionId,
        array $annotationIds,
        array $explicitDeletedPromotedSourceKeys
    ): array {
        $ownership = $this->resolvePdfStateOwnership($document);

        $normalizedAnnotationIds = array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? trim($v) : '',
            $annotationIds
        )));

        $deletedPromotedSourceKeyLookup = [];
        foreach ($explicitDeletedPromotedSourceKeys as $key) {
            if (!is_string($key)) {
                continue;
            }
            $normalized = trim($key);
            if ($this->isPromotedSourceKey($normalized)) {
                $deletedPromotedSourceKeyLookup[$normalized] = true;
            }
        }

        foreach ($normalizedAnnotationIds as $annotationId) {
            $recordQuery = PdfState::where('document_id', $document->id)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.id')) = ?", [$annotationId])
                ->where('state', '!=', 'extracted');
            $this->applyPdfStateOwnershipScope($recordQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
            $record = $recordQuery->first();

            if (!$record) {
                continue;
            }

            $annotationData = is_array($record->annotation_data) ? $record->annotation_data : [];
            $recordSourceKey = trim((string) ($annotationData['promotedSourceKey'] ?? ''));
            if (!empty($annotationData['promotedFromExtraction']) && $this->isPromotedSourceKey($recordSourceKey)) {
                $deletedPromotedSourceKeyLookup[$recordSourceKey] = true;
            }

            $record->annotation_data = $annotationData;
            $record->state = 'deleted';
            $record->save();
        }

        $deletedPromotedSourceKeys = array_keys(array_filter(
            $deletedPromotedSourceKeyLookup,
            fn ($enabled, $sourceKey) => $enabled && $this->isPromotedSourceKey((string) $sourceKey),
            ARRAY_FILTER_USE_BOTH
        ));
        sort($deletedPromotedSourceKeys);

        foreach ($deletedPromotedSourceKeys as $sourceKey) {
            $promotedDeleteQuery = PdfState::query()
                ->where('document_id', $document->id)
                ->where('state', '!=', 'deleted')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.promotedSourceKey')) = ?", [$sourceKey]);
            $this->applyPdfStateOwnershipScope($promotedDeleteQuery, $ownership['user_id'], $ownership['admin_id'], $sessionId);
            $promotedDeleteQuery->update(['state' => 'deleted']);
        }

        return $deletedPromotedSourceKeys;
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
            'deleted_annotation_ids' => 'nullable|array',
            'deleted_annotation_ids.*' => 'string',
            'deleted_promoted_source_keys' => 'nullable|array',
            'deleted_promoted_source_keys.*' => 'string',
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

        // Apply pending deletions (annotation ids + promoted source keys) inline
        // so the frontend only needs a single round-trip to persist a save that
        // also removed annotations. The helper performs the same PdfState marking
        // that the dedicated deleteAnnotations endpoint does; suppression-record
        // bookkeeping is handled below by syncDeletedPromotedSourceKeysForSession.
        $explicitDeletedKeys = is_array($validated['deleted_promoted_source_keys'] ?? null)
            ? array_values(array_filter($validated['deleted_promoted_source_keys'], 'is_string'))
            : [];
        $deletedAnnotationIds = is_array($validated['deleted_annotation_ids'] ?? null)
            ? $validated['deleted_annotation_ids']
            : [];
        $combinedDeletedKeys = $explicitDeletedKeys;
        if (!empty($deletedAnnotationIds) || !empty($explicitDeletedKeys)) {
            $combinedDeletedKeys = $this->applyAnnotationDeletions(
                $document,
                $sessionId,
                $deletedAnnotationIds,
                $explicitDeletedKeys
            );
        }

        $deletedPromotedSourceKeys = $this->mergeDeletedPromotedSourceKeys(
            $document,
            $sessionId,
            $combinedDeletedKeys,
            $sessionAnnotationsPayload
        );

        if ($sessionId !== '') {
            $this->upsertPdfStateSessionSnapshot(
                $document,
                $sessionId,
                $sessionAnnotationsPayload,
                'saved'
            );
            $this->syncDeletedPromotedSourceKeysForSession(
                $document,
                $sessionId,
                $deletedPromotedSourceKeys
            );

            $this->upsertPdfAcroFormSessionState(
                $document,
                $sessionId,
                $acroFormEntries,
                $this->resolvePdfStateOwnership($document),
                'saved'
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Annotation state saved.',
            'session_id' => $sessionId !== '' ? $sessionId : null,
        ]);
    }

    public function saveAcroFormState(Request $request, Document $document)
    {
        $validated = $request->validate([
            'acro_form_entries' => 'nullable|array',
            'session_id' => 'nullable|string',
        ]);

        $acroFormEntries = $this->normalizeAcroFormEntriesForPersistence(
            is_array($validated['acro_form_entries'] ?? null) ? $validated['acro_form_entries'] : []
        );
        if (empty($acroFormEntries)) {
            return response()->json([
                'success' => true,
                'message' => 'No AcroForm changes to save.',
                'saved_count' => 0,
            ]);
        }

        $sessionId = is_string($validated['session_id'] ?? null)
            ? trim((string) $validated['session_id'])
            : '';
        if ($sessionId === '') {
            $sessionId = $request->session()->getId();
        }

        $savedCount = $this->upsertPdfAcroFormSessionState(
            $document,
            $sessionId,
            $acroFormEntries,
            $this->resolvePdfStateOwnership($document),
            'saved'
        );

        return response()->json([
            'success' => true,
            'message' => 'AcroForm state saved.',
            'saved_count' => $savedCount,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Stamp annotations onto a temporary copy of the PDF and serve it as a
     * file download. Never overwrites document.path. Also saves annotation state to DB.
     */
    public function downloadAnnotatedPdf(Request $request, Document $document)
    {
        $this->requireDocumentPdfUnlock($request, $document);
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
            'use_pdfjs_visible_export' => 'nullable|boolean',
            'use_conversion_safe_export' => 'nullable|boolean',
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
        $usePdfjsVisibleExport = $request->boolean('use_pdfjs_visible_export');
        // Searchable source text must never survive underneath a painted
        // replacement. Enforce destructive source-glyph removal for every
        // PDF.js-visible export, including requests from older cached clients
        // that do not yet send use_conversion_safe_export.
        $useConversionSafeExport = $usePdfjsVisibleExport;
        // The browser payload is the live editor state and must remain
        // authoritative. Re-merging a prior PdfState row here used to replace
        // current style flags, font choices, and rich-text mode whenever the
        // text itself was unchanged, producing a download that looked like an
        // older save. Persistence happens after the PDF has been generated.
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
        $useExactCleanRebuild = $useExactDownloadPath && !$usePdfjsVisibleExport && !empty($sessionAnnotationsPayload);
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
        } elseif ($usePdfjsVisibleExport) {
            $sourcePdfPath = $pdfPath;
            $preservePdfPath = $pdfPath;
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
        } elseif ($usePdfjsVisibleExport) {
            // The PDF.js viewer renders the original PDF and only paints custom
            // source masks / overlay annotations on top. Export must follow that
            // same visible model, not the clean rebuild that re-stamps every
            // promoted extraction block. Do not add a separate pre-redaction
            // pass here: apply_annotations_direct_new.py owns source masking,
            // neighbor-safe erasure, and replacement stamping in one pass.
            $annotationsPayload = $this->filterAnnotationsForPdfjsVisibleExport($annotationsPayload);
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
        if ($useConversionSafeExport) {
            // Search, copy/paste, accessibility tools, and downstream
            // converters inspect the content stream rather than only its final
            // painted appearance. Mark this transient payload so source glyphs
            // are removed before replacements are stamped; never persist it.
            $preparedAnnotationsForPython = array_map(static function ($annotation) {
                if (!is_array($annotation)) {
                    return $annotation;
                }
                $annotation['conversionSafeSourceRedaction'] = true;
                return $annotation;
            }, $preparedAnnotationsForPython);
        }
        // Stamp __documentId on every annotation so apply_annotations_direct_new.py
        // can load this document's embedded-font metadata (temp/embedded_fonts_{id}.json)
        // and resolve `ann.fontFamily` like "DejaVuSans" to the actual embedded TTF.
        // Without this, resolve_embedded_font_entry() returns None and the export
        // falls back to FONT_FILE_VARIANTS["Helvetica"] (Arimo), so the saved PDF
        // does not match the editor display which loads PDF_<cleanName> via @font-face.
        $preparedAnnotationsForPython = array_map(static function ($annotation) use ($document) {
            if (!is_array($annotation)) {
                return $annotation;
            }
            $annotation['__documentId'] = $document->id;
            return $annotation;
        }, $preparedAnnotationsForPython);
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

        $containsFieldAnnotations = count(array_filter(
            $preparedAnnotationsForPython,
            static fn ($annotation) => is_array($annotation)
                && strtolower((string) ($annotation['type'] ?? '')) === 'field'
        )) > 0;
        $script = base_path(
            ($useExactDownloadPath || $usePdfjsVisibleExport || $containsFieldAnnotations)
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
            $this->syncDeletedPromotedSourceKeysForSession(
                $document,
                $sessionId,
                $deletedPromotedSourceKeys
            );

            $this->upsertPdfAcroFormSessionState(
                $document,
                $sessionId,
                $acroFormEntries,
                $this->resolvePdfStateOwnership($document),
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

        $requestedSessionId = is_string($validated['session_id'] ?? null)
            ? trim((string) $validated['session_id'])
            : '';
        $sessionId = $requestedSessionId;
        $editorOwnership = $this->resolvePdfStateOwnership($document);

        if ($sessionId === '' && (($editorOwnership['user_id'] ?? null) !== null || ($editorOwnership['admin_id'] ?? null) !== null)) {
            $sessionLookupQuery = PdfAcroForm::query()
                ->where('document_id', $document->id)
                ->orderByDesc('updated_at');

            if (($editorOwnership['user_id'] ?? null) !== null) {
                $sessionLookupQuery->where('user_id', $editorOwnership['user_id']);
            } else {
                $sessionLookupQuery->where('admin_id', $editorOwnership['admin_id']);
            }

            $sessionId = (string) ($sessionLookupQuery->value('sess_id') ?? '');
        }

        $query = PdfAcroForm::query()
            ->where('document_id', $document->id);

        if ($sessionId !== '') {
            $query->where('sess_id', $sessionId);

            if ($requestedSessionId === '' && ($editorOwnership['user_id'] ?? null) !== null) {
                $query->where('user_id', $editorOwnership['user_id']);
            } elseif ($requestedSessionId === '' && ($editorOwnership['admin_id'] ?? null) !== null) {
                $query->where('admin_id', $editorOwnership['admin_id']);
            }
        } else {
            $query->whereRaw('1 = 0');
        }

        $entries = $query
            ->orderByRaw("CASE WHEN state = 'saved' THEN 0 ELSE 1 END")
            ->orderBy('page_num')
            ->orderByDesc('updated_at')
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
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:51200'],
        ]);

        if ($response = $this->consumeMonthlyActionQuota($request)) {
            return $response;
        }

        $level = $validated['level'];
        $embedFonts = $validated['embed_fonts'] ?? true;
        $srgbProfile = $validated['srgb_profile'] ?? true;

        $uploadedInputPath = null;
        if ($request->hasFile('pdf')) {
            $upload = $request->file('pdf');
            if (!$upload->isValid()) {
                return response()->json(['success' => false, 'message' => 'Invalid PDF upload.'], 422);
            }
            $uploadedInputPath = Storage::path('documents/temp_pdfa_input_' . Str::uuid() . '.pdf');
            $upload->move(dirname($uploadedInputPath), basename($uploadedInputPath));
            $inputPath = $uploadedInputPath;
        } else {
            if (!$document->path || !Storage::exists($document->path)) {
                return response()->json(['success' => false, 'message' => 'Current PDF is missing.'], 404);
            }
            $inputPath = Storage::path($document->path);
        }
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
        if ($uploadedInputPath && file_exists($uploadedInputPath)) {
            @unlink($uploadedInputPath);
        }

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

    public function queueWordConversion(Request $request, Document $document)
    {
        $validated = $request->validate([
            'layout' => ['required', 'string', 'in:flow,exact'],
            'include_images' => ['boolean'],
            'ocr' => ['boolean'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:51200'],
        ]);

        return $this->queueDocumentConversion($request, $document, 'word', [
            'layout' => $validated['layout'],
            'include_images' => $validated['include_images'] ?? true,
            'ocr' => $validated['ocr'] ?? false,
            // The PDF.js editor posts the completed PDF. Mark it for Adobe
            // preparation so native text and vector shapes remain semantic,
            // while image-backed drawings and signatures stay flattened.
            'visual_fidelity' => $request->hasFile('pdf'),
        ]);
    }

    public function queueExcelConversion(Request $request, Document $document)
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:tables,all'],
            'merge_cells' => ['boolean'],
            'sheet_per_page' => ['boolean'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:51200'],
        ]);

        return $this->queueDocumentConversion($request, $document, 'excel', [
            'mode' => $validated['mode'],
            'merge_cells' => $validated['merge_cells'] ?? true,
            'sheet_per_page' => $validated['sheet_per_page'] ?? true,
        ]);
    }

    private function queueDocumentConversion(
        Request $request,
        Document $document,
        string $format,
        array $options,
    ) {
        if (! $this->hasEditorAuthentication()) {
            return response()->json([
                'success' => false,
                'message' => 'Sign in to convert PDFs to Word or Excel.',
                'login_url' => route('login'),
            ], 401);
        }

        $actor = $this->resolveEditorActor();
        if (! $actor instanceof User && ! $actor instanceof Admin) {
            return response()->json(['success' => false, 'message' => 'Conversion account not found.'], 401);
        }

        $uuid = Str::uuid()->toString();
        $directory = "documents/conversions/{$uuid}";
        $inputPath = "{$directory}/input.pdf";
        $extension = $format === 'word' ? 'docx' : 'xlsx';
        $outputPath = "{$directory}/output.{$extension}";

        Storage::makeDirectory($directory);
        if ($request->hasFile('pdf')) {
            $upload = $request->file('pdf');
            if (! $upload->isValid()) {
                Storage::deleteDirectory($directory);

                return response()->json(['success' => false, 'message' => 'Invalid PDF upload.'], 422);
            }
            $upload->move(dirname(Storage::path($inputPath)), basename($inputPath));
        } else {
            if (! $document->path || ! Storage::exists($document->path)) {
                Storage::deleteDirectory($directory);

                return response()->json(['success' => false, 'message' => 'Current PDF is missing.'], 404);
            }
            if (! Storage::copy($document->path, $inputPath)) {
                Storage::deleteDirectory($directory);

                return response()->json(['success' => false, 'message' => 'Unable to prepare the PDF for conversion.'], 500);
            }
        }

        try {
            $quote = app(DocumentExportConversionService::class)->quote(Storage::path($inputPath));
        } catch (\Throwable $exception) {
            Storage::deleteDirectory($directory);

            return response()->json([
                'success' => false,
                'message' => 'Unable to price this conversion: '.$exception->getMessage(),
            ], 422);
        }

        if ($balanceResponse = $this->documentConversionBalanceResponse($actor, $quote)) {
            Storage::deleteDirectory($directory);

            return $balanceResponse;
        }
        if ($quotaResponse = $this->consumeMonthlyActionQuota($request)) {
            Storage::deleteDirectory($directory);

            return $quotaResponse;
        }

        $baseName = pathinfo($document->original_name, PATHINFO_FILENAME) ?: 'document';
        $conversion = DocumentConversion::create([
            'uuid' => $uuid,
            'document_id' => $document->id,
            'user_id' => $actor instanceof User ? $actor->id : null,
            'admin_id' => $actor instanceof Admin ? $actor->id : null,
            'format' => $format,
            'status' => DocumentConversion::STATUS_QUEUED,
            'progress' => 5,
            'options' => $options,
            'quote' => $quote,
            'input_path' => $inputPath,
            'output_path' => $outputPath,
            'download_name' => "{$baseName}.{$extension}",
            'content_type' => $format === 'word'
                ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'queued_at' => now(),
        ]);

        try {
            ConvertDocumentExportJob::dispatch($conversion->id);
        } catch (\Throwable $exception) {
            $conversion->forceFill([
                'status' => DocumentConversion::STATUS_FAILED,
                'progress' => 100,
                'error' => 'The conversion queue is unavailable. Please try again shortly.',
                'completed_at' => now(),
            ])->save();
            Storage::deleteDirectory($directory);
            Log::error('Unable to dispatch document conversion to Horizon.', [
                'conversion_id' => $uuid,
                'document_id' => $document->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The conversion queue is unavailable. Please try again shortly.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'queued' => true,
            'status' => DocumentConversion::STATUS_QUEUED,
            'conversion_id' => $conversion->uuid,
            'status_url' => route('documents.conversions.status', [$document, $conversion]),
            'charge_usd' => $quote['charge_usd'],
            'page_count' => $quote['page_count'],
            'billable_transactions' => $quote['transactions'],
        ], 202);
    }

    public function conversionStatus(
        Request $request,
        Document $document,
        DocumentConversion $conversion,
    ) {
        $this->authorizeDocumentConversionAccess($conversion, $document);
        $conversion->refresh();

        $payload = [
            'success' => $conversion->status !== DocumentConversion::STATUS_FAILED,
            'conversion_id' => $conversion->uuid,
            'status' => $conversion->status,
            'progress' => $conversion->progress,
        ];

        if ($conversion->status === DocumentConversion::STATUS_FAILED) {
            $payload['message'] = $conversion->error ?: 'The document conversion failed.';
        }
        if ($conversion->status === DocumentConversion::STATUS_COMPLETED) {
            $payload = array_merge($payload, $conversion->result ?? [], [
                'download_token' => $conversion->uuid,
                'download_name' => $conversion->download_name,
            ]);
        }

        return response()->json($payload);
    }

    private function authorizeDocumentConversionAccess(
        DocumentConversion $conversion,
        Document $document,
    ): void {
        abort_unless((int) $conversion->document_id === (int) $document->id, 404);
        $userId = $this->currentWebUserId();
        $adminId = $this->currentAdminId();
        abort_unless(
            ($userId !== null && (int) $conversion->user_id === $userId)
            || ($adminId !== null && (int) $conversion->admin_id === $adminId),
            403
        );
    }

    public function convertToWord(Request $request, Document $document)
    {
        if (!$this->hasEditorAuthentication()) {
            return response()->json([
                'success' => false,
                'message' => 'Sign in to convert PDFs to Word.',
                'login_url' => route('login'),
            ], 401);
        }

        $validated = $request->validate([
            'layout' => ['required', 'string', 'in:flow,exact'],
            'include_images' => ['boolean'],
            'ocr' => ['boolean'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:51200'],
        ]);

        $layout = $validated['layout'];
        $includeImages = $validated['include_images'] ?? true;
        $ocr = $validated['ocr'] ?? false;

        $uploadedInputPath = null;
        if ($request->hasFile('pdf')) {
            $upload = $request->file('pdf');
            if (!$upload->isValid()) {
                return response()->json(['success' => false, 'message' => 'Invalid PDF upload.'], 422);
            }
            $uploadedInputPath = Storage::path('documents/temp_word_input_' . Str::uuid() . '.pdf');
            $upload->move(dirname($uploadedInputPath), basename($uploadedInputPath));
            $inputPath = $uploadedInputPath;
        } else {
            if (!$document->path || !Storage::exists($document->path)) {
                return response()->json(['success' => false, 'message' => 'Current PDF is missing.'], 404);
            }
            $inputPath = Storage::path($document->path);
        }
        $tempOutputPath = Storage::path('documents/temp_word_' . Str::uuid() . '.docx');

        try {
            $conversionQuote = $this->documentConversionQuote($inputPath);
        } catch (\Throwable $e) {
            if ($uploadedInputPath && file_exists($uploadedInputPath)) {
                @unlink($uploadedInputPath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Unable to price this conversion: ' . $e->getMessage(),
            ], 422);
        }

        $actor = $this->resolveEditorActor();
        if ($balanceResponse = $this->documentConversionBalanceResponse($actor, $conversionQuote)) {
            if ($uploadedInputPath && file_exists($uploadedInputPath)) {
                @unlink($uploadedInputPath);
            }

            return $balanceResponse;
        }

        if ($response = $this->consumeMonthlyActionQuota($request)) {
            if ($uploadedInputPath && file_exists($uploadedInputPath)) {
                @unlink($uploadedInputPath);
            }

            return $response;
        }

        $settings = DocumentConversionSetting::current();
        $requestedProvider = $settings->word_provider;
        $providerUsed = $requestedProvider;
        $providerFallbackUsed = false;
        $providerError = null;

        try {
            if ($requestedProvider === DocumentConversionSetting::PROVIDER_ADOBE) {
                try {
                    $result = app(AdobePdfServices::class)->export($inputPath, $tempOutputPath, 'docx');
                } catch (\Throwable $e) {
                    if (!$settings->fallback_to_local) {
                        throw $e;
                    }

                    $providerError = $e->getMessage();
                    $providerUsed = DocumentConversionSetting::PROVIDER_LOCAL;
                    $providerFallbackUsed = true;
                    @unlink($tempOutputPath);
                    Log::warning('Adobe Word export failed; using local converter.', [
                        'document_id' => $document->id,
                        'error' => $providerError,
                    ]);
                    $result = $this->runLocalWordConversion(
                        $inputPath,
                        $tempOutputPath,
                        $layout,
                        $includeImages,
                        $ocr
                    );
                }
            } else {
                $providerUsed = DocumentConversionSetting::PROVIDER_LOCAL;
                $result = $this->runLocalWordConversion(
                    $inputPath,
                    $tempOutputPath,
                    $layout,
                    $includeImages,
                    $ocr
                );
            }
        } catch (\Throwable $e) {
            if (file_exists($tempOutputPath)) {
                @unlink($tempOutputPath);
            }

            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => 'Convert to Word',
                    'category' => 'word_export',
                    'details' => [
                        'layout' => $layout,
                        'include_images' => $includeImages,
                        'ocr' => $ocr,
                        'provider_requested' => $requestedProvider,
                        'provider_used' => $providerUsed,
                        'error' => $e->getMessage(),
                    ],
                    'document_id' => $document->id,
                    'status' => 'failed',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Word conversion failed: ' . $e->getMessage(),
            ], 500);
        } finally {
            if ($uploadedInputPath && file_exists($uploadedInputPath)) {
                @unlink($uploadedInputPath);
            }
        }

        if (!file_exists($tempOutputPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Word output file was not created',
            ], 500);
        }

        try {
            $billing = $this->chargeDocumentConversion(
                $actor,
                $conversionQuote,
                $document,
                'word',
                $providerUsed
            );
        } catch (InsufficientCreditBalanceException $e) {
            @unlink($tempOutputPath);

            return $this->insufficientDocumentConversionBalanceResponse($e->available, $conversionQuote);
        } catch (\Throwable $e) {
            @unlink($tempOutputPath);
            Log::error('Unable to charge Word conversion.', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The Word document was created, but billing could not be completed. No download was issued.',
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
                    'engine' => $result['engine'] ?? 'netkit',
                    'provider_requested' => $requestedProvider,
                    'provider_used' => $providerUsed,
                    'provider_fallback_used' => $providerFallbackUsed,
                    'provider_error' => $providerError,
                    'conversion_charge_usd' => $conversionQuote['charge_usd'],
                    'conversion_transactions' => $conversionQuote['transactions'],
                    'page_count' => $conversionQuote['page_count'],
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
            'engine' => $result['engine'] ?? 'netkit',
            'provider' => $providerUsed,
            'provider_fallback_used' => $providerFallbackUsed,
            'charge_usd' => $conversionQuote['charge_usd'],
            'credit_balance' => $billing['credit_balance'],
            'page_count' => $conversionQuote['page_count'],
            'billable_transactions' => $conversionQuote['transactions'],
        ]);
    }

    public function convertToExcel(Request $request, Document $document)
    {
        if (!$this->hasEditorAuthentication()) {
            return response()->json([
                'success' => false,
                'message' => 'Sign in to convert PDFs to Excel.',
                'login_url' => route('login'),
            ], 401);
        }

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:tables,all'],
            'merge_cells' => ['boolean'],
            'sheet_per_page' => ['boolean'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:51200'],
        ]);

        $mode = $validated['mode'];
        $mergeCells = $validated['merge_cells'] ?? true;
        $sheetPerPage = $validated['sheet_per_page'] ?? true;

        $uploadedInputPath = null;
        if ($request->hasFile('pdf')) {
            $upload = $request->file('pdf');
            if (!$upload->isValid()) {
                return response()->json(['success' => false, 'message' => 'Invalid PDF upload.'], 422);
            }
            $uploadedInputPath = Storage::path('documents/temp_excel_input_' . Str::uuid() . '.pdf');
            $upload->move(dirname($uploadedInputPath), basename($uploadedInputPath));
            $inputPath = $uploadedInputPath;
        } else {
            if (!$document->path || !Storage::exists($document->path)) {
                return response()->json(['success' => false, 'message' => 'Current PDF is missing.'], 404);
            }
            $inputPath = Storage::path($document->path);
        }
        $tempOutputPath = Storage::path('documents/temp_excel_' . Str::uuid() . '.xlsx');

        try {
            $conversionQuote = $this->documentConversionQuote($inputPath);
        } catch (\Throwable $e) {
            if ($uploadedInputPath && file_exists($uploadedInputPath)) {
                @unlink($uploadedInputPath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Unable to price this conversion: ' . $e->getMessage(),
            ], 422);
        }

        $actor = $this->resolveEditorActor();
        if ($balanceResponse = $this->documentConversionBalanceResponse($actor, $conversionQuote)) {
            if ($uploadedInputPath && file_exists($uploadedInputPath)) {
                @unlink($uploadedInputPath);
            }

            return $balanceResponse;
        }

        if ($response = $this->consumeMonthlyActionQuota($request)) {
            if ($uploadedInputPath && file_exists($uploadedInputPath)) {
                @unlink($uploadedInputPath);
            }

            return $response;
        }

        $settings = DocumentConversionSetting::current();
        $requestedProvider = $settings->excel_provider;
        $providerUsed = $requestedProvider;
        $providerFallbackUsed = false;
        $providerError = null;

        try {
            if ($requestedProvider === DocumentConversionSetting::PROVIDER_ADOBE) {
                try {
                    $result = app(AdobePdfServices::class)->export($inputPath, $tempOutputPath, 'xlsx');
                } catch (\Throwable $e) {
                    if (!$settings->fallback_to_local) {
                        throw $e;
                    }

                    $providerError = $e->getMessage();
                    $providerUsed = DocumentConversionSetting::PROVIDER_LOCAL;
                    $providerFallbackUsed = true;
                    @unlink($tempOutputPath);
                    Log::warning('Adobe Excel export failed; using local converter.', [
                        'document_id' => $document->id,
                        'error' => $providerError,
                    ]);
                    $result = $this->runLocalExcelConversion(
                        $inputPath,
                        $tempOutputPath,
                        $mode,
                        $mergeCells,
                        $sheetPerPage
                    );
                }
            } else {
                $providerUsed = DocumentConversionSetting::PROVIDER_LOCAL;
                $result = $this->runLocalExcelConversion(
                    $inputPath,
                    $tempOutputPath,
                    $mode,
                    $mergeCells,
                    $sheetPerPage
                );
            }
        } catch (\Throwable $e) {
            if (file_exists($tempOutputPath)) {
                @unlink($tempOutputPath);
            }

            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => 'Convert to Excel',
                    'category' => 'excel_export',
                    'details' => [
                        'mode' => $mode,
                        'merge_cells' => $mergeCells,
                        'sheet_per_page' => $sheetPerPage,
                        'provider_requested' => $requestedProvider,
                        'provider_used' => $providerUsed,
                        'error' => $e->getMessage(),
                    ],
                    'document_id' => $document->id,
                    'status' => 'failed',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Excel conversion failed: ' . $e->getMessage(),
            ], 500);
        } finally {
            if ($uploadedInputPath && file_exists($uploadedInputPath)) {
                @unlink($uploadedInputPath);
            }
        }

        if (!file_exists($tempOutputPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Excel output file was not created',
            ], 500);
        }

        try {
            $billing = $this->chargeDocumentConversion(
                $actor,
                $conversionQuote,
                $document,
                'excel',
                $providerUsed
            );
        } catch (InsufficientCreditBalanceException $e) {
            @unlink($tempOutputPath);

            return $this->insufficientDocumentConversionBalanceResponse($e->available, $conversionQuote);
        } catch (\Throwable $e) {
            @unlink($tempOutputPath);
            Log::error('Unable to charge Excel conversion.', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The Excel document was created, but billing could not be completed. No download was issued.',
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
                    'tables_found' => $result['tables_found'] ?? 0,
                    'sheets' => $result['sheets'] ?? 0,
                    'effective_mode' => $result['effective_mode'] ?? $mode,
                    'fallback_used' => $result['fallback_used'] ?? false,
                    'layout_engine' => $result['layout_engine'] ?? null,
                    'images_embedded' => $result['images_embedded'] ?? 0,
                    'engine' => $result['engine'] ?? 'netkit',
                    'provider_requested' => $requestedProvider,
                    'provider_used' => $providerUsed,
                    'provider_fallback_used' => $providerFallbackUsed,
                    'provider_error' => $providerError,
                    'conversion_charge_usd' => $conversionQuote['charge_usd'],
                    'conversion_transactions' => $conversionQuote['transactions'],
                    'page_count' => $conversionQuote['page_count'],
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
            'tables_found' => $result['tables_found'] ?? 0,
            'sheets' => $result['sheets'] ?? 0,
            'effective_mode' => $result['effective_mode'] ?? $mode,
            'fallback_used' => $result['fallback_used'] ?? false,
            'layout_engine' => $result['layout_engine'] ?? null,
            'images_embedded' => $result['images_embedded'] ?? 0,
            'engine' => $result['engine'] ?? 'netkit',
            'provider' => $providerUsed,
            'provider_fallback_used' => $providerFallbackUsed,
            'charge_usd' => $conversionQuote['charge_usd'],
            'credit_balance' => $billing['credit_balance'],
            'page_count' => $conversionQuote['page_count'],
            'billable_transactions' => $conversionQuote['transactions'],
        ]);
    }

    private function documentConversionQuote(string $inputPath): array
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
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

        return app(DocumentConversionPricing::class)->quote($pageCount);
    }

    private function documentConversionBalanceResponse(mixed $actor, array $quote)
    {
        if (!$actor instanceof User && !$actor instanceof Admin) {
            return response()->json([
                'success' => false,
                'message' => 'Sign in to convert PDFs to Word or Excel.',
                'login_url' => route('login'),
            ], 401);
        }

        $available = (float) $actor->credit_balance;
        if ($available + 0.000001 >= (float) $quote['charge_usd']) {
            return null;
        }

        return $this->insufficientDocumentConversionBalanceResponse($available, $quote);
    }

    private function insufficientDocumentConversionBalanceResponse(float $available, array $quote)
    {
        return response()->json([
            'success' => false,
            'message' => sprintf(
                'Insufficient balance. This %d-page conversion costs $%.2f, but your balance is $%.4f. Please add credits.',
                $quote['page_count'],
                $quote['charge_usd'],
                $available
            ),
            'credit_balance' => $available,
            'required_credits' => $quote['charge_usd'],
            'page_count' => $quote['page_count'],
            'billable_transactions' => $quote['transactions'],
        ], 402);
    }

    private function chargeDocumentConversion(
        mixed $actor,
        array $quote,
        Document $document,
        string $format,
        string $provider
    ): array {
        $amount = (float) $quote['charge_usd'];
        if ($amount <= 0) {
            return ['credit_balance' => (float) $actor->credit_balance, 'transaction_id' => null];
        }

        $metadata = [
            'document_id' => $document->id,
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
                    : 'netkit-local-converter',
                description: $description,
                metadata: $metadata,
            );

            return [
                'credit_balance' => (float) $transaction->balance_after,
                'transaction_id' => $transaction->id,
            ];
        }

        if ($actor instanceof Admin) {
            $actor->debitBalanceIfSufficient($amount);

            return ['credit_balance' => (float) $actor->credit_balance, 'transaction_id' => null];
        }

        throw new \RuntimeException('No authenticated conversion account was found.');
    }

    private function runLocalWordConversion(
        string $inputPath,
        string $outputPath,
        string $layout,
        bool $includeImages,
        bool $ocr
    ): array {
        $requiredModules = $layout === 'exact' && $includeImages && !$ocr
            ? ['fitz', 'docx', 'pdf2docx']
            : ['fitz', 'docx'];
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor($requiredModules);
        $pythonScript = base_path('python/pdf-editor/convert_to_word.py');
        $command = sprintf(
            '%s %s %s %s --layout %s %s %s --json 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($layout),
            $includeImages ? '--images' : '--no-images',
            $ocr ? '--ocr' : ''
        );

        return $this->parseLocalConversionResult(shell_exec($command), 'Word');
    }

    private function runLocalExcelConversion(
        string $inputPath,
        string $outputPath,
        string $mode,
        bool $mergeCells,
        bool $sheetPerPage
    ): array {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor(['fitz', 'openpyxl']);
        $pythonScript = base_path('python/pdf-editor/convert_to_excel.py');
        $command = sprintf(
            '%s %s %s %s --mode %s %s %s --json 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
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
        if ($output) {
            foreach (explode("\n", trim($output)) as $line) {
                $decoded = json_decode(trim($line), true);
                if (is_array($decoded)) {
                    $result = $decoded;
                }
            }
        }

        if (!$result || !($result['success'] ?? false)) {
            throw new \RuntimeException(
                $result['error'] ?? "{$format} conversion failed. Output: " . ($output ?? 'none')
            );
        }

        return $result;
    }

    public function unlockPdfPassword(Request $request, Document $document)
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:1', 'max:255'],
        ]);

        if (!$this->documentHasPdfPassword($document)) {
            return response()->json([
                'success' => true,
                'protected' => false,
                'unlock_token' => null,
            ]);
        }

        if (!Hash::check((string) $validated['password'], (string) $document->pdf_password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Password is incorrect.',
                'code' => 'incorrect_password',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'protected' => true,
            'unlock_token' => $this->issueDocumentPdfUnlockToken($request, $document),
        ]);
    }

    public function encryptPdf(Request $request, Document $document)
    {
        $request->merge([
            'action' => $request->input('action', 'set'),
            'algorithm' => $request->input('algorithm', 'aes-128'),
        ]);
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:set,remove'],
            'password' => ['required_if:action,set', 'nullable', 'string', 'min:1', 'max:255', 'confirmed'],
            'password_confirmation' => ['required_if:action,set', 'nullable', 'string', 'max:255'],
            'current_password' => ['required_if:action,remove', 'nullable', 'string', 'min:1', 'max:255'],
            'algorithm' => ['required', 'string', 'in:aes-128,aes-256'],
            'persist_protection' => ['nullable', 'boolean'],
            'pdf' => [
                Rule::requiredIf(
                    fn () => $request->input('action') === 'remove'
                        && !$request->boolean('persist_protection')
                ),
                'nullable',
                'file',
                'mimes:pdf',
                'max:51200',
            ],
        ]);
        $action = (string) $validated['action'];
        $persistProtection = $request->boolean('persist_protection');

        if ($response = $this->consumeMonthlyActionQuota($request)) {
            return $response;
        }

        if ($persistProtection) {
            $currentPassword = (string) ($validated['current_password'] ?? '');
            $currentlyProtected = $this->documentHasPdfPassword($document);

            if ($action === 'set') {
                if ($currentlyProtected && (
                    $currentPassword === ''
                    || !Hash::check($currentPassword, (string) $document->pdf_password_hash)
                )) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Current password is incorrect.',
                        'code' => 'incorrect_password',
                    ], 422);
                }

                $document->forceFill([
                    'pdf_password_hash' => Hash::make((string) $validated['password']),
                    'pdf_password_algorithm' => (string) $validated['algorithm'],
                    'pdf_password_set_at' => now(),
                ])->save();
                $unlockToken = $this->issueDocumentPdfUnlockToken($request, $document);

                return response()->json([
                    'success' => true,
                    'action' => 'set',
                    'persisted' => true,
                    'protected' => true,
                    'algorithm' => (string) $validated['algorithm'],
                    'unlock_token' => $unlockToken,
                ]);
            }

            if (!$currentlyProtected) {
                return response()->json([
                    'success' => false,
                    'message' => 'This PDF does not have a password.',
                    'code' => 'not_encrypted',
                ], 422);
            }
            if (!Hash::check($currentPassword, (string) $document->pdf_password_hash)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect.',
                    'code' => 'incorrect_password',
                ], 422);
            }

            $document->forceFill([
                'pdf_password_hash' => null,
                'pdf_password_algorithm' => null,
                'pdf_password_set_at' => null,
            ])->save();
            $this->forgetDocumentPdfUnlockToken($request, $document);

            return response()->json([
                'success' => true,
                'action' => 'remove',
                'persisted' => true,
                'protected' => false,
            ]);
        }

        if (
            $this->documentHasPdfPassword($document)
            && $action === 'set'
            && !Hash::check((string) ($validated['password'] ?? ''), (string) $document->pdf_password_hash)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Password is incorrect.',
                'code' => 'incorrect_password',
            ], 422);
        }

        $inputPath = null;
        $uploadedInput = false;
        if ($request->hasFile('pdf')) {
            $upload = $request->file('pdf');
            if (!$upload->isValid()) {
                return response()->json(['success' => false, 'message' => 'Invalid PDF upload.'], 422);
            }
            $inputPath = Storage::path('documents/temp_encrypt_input_' . Str::uuid() . '.pdf');
            $upload->move(dirname($inputPath), basename($inputPath));
            $uploadedInput = true;
        } else {
            if (!$document->path || !Storage::exists($document->path)) {
                return response()->json(['success' => false, 'message' => 'Current PDF is missing.'], 404);
            }
            $inputPath = Storage::path($document->path);
        }

        $tempOutputPath = Storage::path(
            'documents/temp_password_' . $action . '_' . Str::uuid() . '.pdf'
        );
        $passwordPayloadDir = storage_path('app/temp');
        if (!is_dir($passwordPayloadDir)) {
            @mkdir($passwordPayloadDir, 0700, true);
        }
        $passwordPayloadPath = tempnam($passwordPayloadDir, 'pdf_password_');
        $passwordPayload = json_encode([
            'password' => $action === 'set' ? (string) ($validated['password'] ?? '') : '',
            'current_password' => (string) ($validated['current_password'] ?? ''),
        ], JSON_THROW_ON_ERROR);
        if ($passwordPayloadPath === false
            || !@chmod($passwordPayloadPath, 0600)
            || @file_put_contents($passwordPayloadPath, $passwordPayload, LOCK_EX) === false) {
            if (is_string($passwordPayloadPath) && file_exists($passwordPayloadPath)) {
                @unlink($passwordPayloadPath);
            }
            if ($uploadedInput && $inputPath && file_exists($inputPath)) {
                @unlink($inputPath);
            }
            return response()->json([
                'success' => false,
                'message' => 'The password request could not be prepared.',
            ], 500);
        }

        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');
        $pythonScript = base_path('python/pdf-editor/encrypt_pdf.py');
        $command = sprintf(
            '%s %s %s %s --action %s --password-file %s --algorithm %s --json 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($pythonScript),
            escapeshellarg($inputPath),
            escapeshellarg($tempOutputPath),
            escapeshellarg($action),
            escapeshellarg($passwordPayloadPath),
            escapeshellarg($validated['algorithm'])
        );

        try {
            $output = shell_exec($command);
        } finally {
            if (file_exists($passwordPayloadPath)) {
                @unlink($passwordPayloadPath);
            }
            if ($uploadedInput && $inputPath && file_exists($inputPath)) {
                @unlink($inputPath);
            }
        }

        $result = null;
        if ($output) {
            foreach (explode("\n", trim($output)) as $line) {
                $decoded = json_decode(trim($line), true);
                if ($decoded !== null) {
                    $result = $decoded;
                    break;
                }
            }
        }

        if (!$result || !($result['success'] ?? false) || !file_exists($tempOutputPath)) {
            if (file_exists($tempOutputPath)) {
                @unlink($tempOutputPath);
            }
            $errorCode = (string) ($result['code'] ?? '');
            $statusCode = in_array($errorCode, [
                'current_password_required',
                'incorrect_password',
                'not_encrypted',
            ], true) ? 422 : 500;
            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => $action === 'remove' ? 'Remove PDF Password' : 'Set PDF Password',
                    'category' => 'pdf_password',
                    'details' => [
                        'password_action' => $action,
                        'algorithm' => $validated['algorithm'],
                        'error_code' => $errorCode ?: 'unknown',
                    ],
                    'document_id' => $document->id,
                    'status' => 'failed',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }
            return response()->json([
                'success' => false,
                'message' => $result['error']
                    ?? ($action === 'remove' ? 'PDF password removal failed.' : 'PDF encryption failed.'),
                'code' => $errorCode ?: 'password_action_failed',
            ], $statusCode);
        }

        $downloadToken = Str::uuid()->toString();
        $baseName = pathinfo($document->original_name, PATHINFO_FILENAME);
        $downloadName = $baseName
            . ($action === 'remove' ? '_password_removed.pdf' : '_password_protected.pdf');

        session()->put("converted_download_{$downloadToken}", [
            'path' => $tempOutputPath,
            'name' => $downloadName,
            'content_type' => 'application/pdf',
            'expires' => now()->addMinutes(10),
        ]);

        if (Auth::check()) {
            UserActivity::create([
                'user_id' => Auth::id(),
                'action' => $action === 'remove' ? 'Remove PDF Password' : 'Set PDF Password',
                'category' => 'pdf_password',
                'details' => [
                    'password_action' => $action,
                    'algorithm' => $validated['algorithm'],
                    'file_size' => filesize($tempOutputPath),
                    'page_count' => $result['page_count'] ?? null,
                ],
                'document_id' => $document->id,
                'status' => 'success',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json([
            'success' => true,
            'action' => $action,
            'download_token' => $downloadToken,
            'download_name' => $downloadName,
            'algorithm' => $validated['algorithm'],
            'encrypted' => (bool) ($result['encrypted'] ?? ($action === 'set')),
            'source_was_encrypted' => (bool) ($result['source_was_encrypted'] ?? false),
            'page_count' => $result['page_count'] ?? null,
        ]);
    }

    public function splitPdf(Request $request, Document $document)
    {
        $pythonBinary = $this->resolvePythonBinaryForPdfEditor('fitz');

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:each,specific,range,custom,selected'],
            'page' => ['nullable', 'integer', 'min:1'],
            'from_page' => ['nullable', 'integer', 'min:1'],
            'to_page' => ['nullable', 'integer', 'min:1'],
            'pages' => ['nullable', 'string'],
            'page_indices' => ['required_if:mode,selected', 'array', 'min:1', 'max:1000'],
            'page_indices.*' => ['integer', 'min:0', 'distinct'],
            'output_name' => ['required_if:mode,selected', 'nullable', 'string', 'max:240'],
            'output_action' => ['nullable', 'string', 'in:download,editor'],
            'session_id' => ['nullable', 'string', 'max:255'],
            'pdf' => ['required_if:mode,selected', 'nullable', 'file', 'mimes:pdf', 'max:51200'],
        ]);

        if ($response = $this->consumeMonthlyActionQuota($request)) {
            return $response;
        }

        if ($validated['mode'] === 'selected') {
            return $this->splitSelectedPdfPages($request, $document, $validated, $pythonBinary);
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

    private function splitSelectedPdfPages(Request $request, Document $document, array $validated, string $pythonBinary)
    {
        $lock = Cache::lock("document:{$document->id}:structure", 180);
        if (!$lock->get()) {
            return response()->json([
                'success' => false,
                'message' => 'Another page operation is already running. Please try again.',
            ], 409);
        }

        $outputDir = null;
        $tokenOutputPath = null;

        try {
            $upload = $request->file('pdf');
            if (!$upload || !$upload->isValid()) {
                return response()->json([
                    'success' => false,
                    'message' => 'The edited PDF could not be prepared for splitting.',
                ], 422);
            }

            $pageIndices = array_values(array_unique(array_map(
                static fn ($value): int => (int) $value,
                $validated['page_indices'] ?? [],
            )));
            sort($pageIndices, SORT_NUMERIC);
            if (empty($pageIndices)) {
                return response()->json(['success' => false, 'message' => 'Select at least one page.'], 422);
            }

            $downloadName = $this->normalizeSplitDownloadName((string) ($validated['output_name'] ?? ''));
            if ($downloadName === '') {
                return response()->json(['success' => false, 'message' => 'Enter a valid PDF filename.'], 422);
            }

            $inputPath = $upload->getRealPath();
            $outputDir = Storage::path('documents/split_' . Str::uuid());
            $pythonScript = base_path('python/pdf-editor/split_pdf.py');
            $oneBasedPages = array_map(static fn (int $index): int => $index + 1, $pageIndices);
            $command = sprintf(
                '%s %s %s %s --mode custom --pages %s --json 2>&1',
                escapeshellarg($pythonBinary),
                escapeshellarg($pythonScript),
                escapeshellarg($inputPath),
                escapeshellarg($outputDir),
                escapeshellarg(implode(',', $oneBasedPages)),
            );

            $outputLines = [];
            $returnCode = 0;
            exec($command, $outputLines, $returnCode);
            $result = null;
            foreach (array_reverse($outputLines) as $line) {
                $decoded = json_decode(trim((string) $line), true);
                if (is_array($decoded)) {
                    $result = $decoded;
                    break;
                }
            }

            if ($returnCode !== 0 || !$result || !($result['success'] ?? false)) {
                $message = (string) ($result['error'] ?? 'The selected pages could not be split.');
                $message = str_replace([$inputPath, (string) $outputDir], 'PDF', $message);
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            $sourcePageCount = (int) ($result['total_pages'] ?? 0);
            if ($sourcePageCount < 1 || end($pageIndices) >= $sourcePageCount) {
                return response()->json([
                    'success' => false,
                    'message' => "A selected page is outside the PDF's {$sourcePageCount}-page range.",
                ], 422);
            }

            $file = $result['files'][0] ?? null;
            $generatedPath = is_array($file) ? (string) ($file['path'] ?? '') : '';
            if ($generatedPath === '' || !is_file($generatedPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The split PDF output was not created.',
                ], 500);
            }

            $storedRelativePath = 'documents/split_result_' . Str::uuid() . '.pdf';
            $tokenOutputPath = Storage::path($storedRelativePath);
            if (!@rename($generatedPath, $tokenOutputPath)) {
                if (!copy($generatedPath, $tokenOutputPath)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The split PDF could not be saved.',
                    ], 500);
                }
                @unlink($generatedPath);
            }
            if (is_dir($outputDir)) {
                @rmdir($outputDir);
            }
            $outputDir = null;
            $outputSize = (int) (filesize($tokenOutputPath) ?: 0);
            $outputAction = ($validated['output_action'] ?? 'download') === 'editor' ? 'editor' : 'download';

            if ($outputAction === 'editor') {
                $splitDocument = Document::create([
                    ...$this->documentOwnershipPayload($document),
                    'original_name' => $downloadName,
                    'path' => $storedRelativePath,
                    'original_backup_path' => $this->createOriginalBackup($storedRelativePath),
                    'mime_type' => 'application/pdf',
                    'size_bytes' => $outputSize,
                    'mode' => 'editor',
                ]);
                $tokenOutputPath = null;
                $this->rememberSessionAccessibleDocument($request, $splitDocument);

                try {
                    ProcessUploadedDocumentJob::dispatch(
                        $splitDocument->id,
                        $this->resolveEditorEmail(),
                        $request->session()->getId(),
                    );
                } catch (\Throwable $error) {
                    Log::warning('Split PDF background processing could not be queued', [
                        'document_id' => $splitDocument->id,
                        'source_document_id' => $document->id,
                        'error' => $error->getMessage(),
                    ]);
                }

                if (Auth::check()) {
                    UserActivity::create([
                        'user_id' => Auth::id(),
                        'action' => 'Split PDF (selected pages)',
                        'category' => 'split_export',
                        'details' => [
                            'output_action' => 'editor',
                            'split_document_id' => $splitDocument->id,
                            'selected_page_count' => count($oneBasedPages),
                            'selected_pages' => $oneBasedPages,
                            'source_page_count' => $sourcePageCount,
                            'output_size' => $outputSize,
                        ],
                        'document_id' => $document->id,
                        'status' => 'success',
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'output_action' => 'editor',
                    'document_id' => $splitDocument->id,
                    'edit_url' => route('documents.editPdfjs', $splitDocument),
                    'download_name' => $downloadName,
                    'selected_pages' => $oneBasedPages,
                    'selected_page_count' => count($oneBasedPages),
                    'source_page_count' => $sourcePageCount,
                    'output_size' => $outputSize,
                ]);
            }

            $downloadToken = Str::uuid()->toString();
            session()->put("converted_download_{$downloadToken}", [
                'path' => $tokenOutputPath,
                'name' => $downloadName,
                'content_type' => 'application/pdf',
                'expires' => now()->addMinutes(10),
            ]);
            $tokenOutputPath = null;

            if (Auth::check()) {
                UserActivity::create([
                    'user_id' => Auth::id(),
                    'action' => 'Split PDF (selected pages)',
                    'category' => 'split_export',
                    'details' => [
                        'output_action' => 'download',
                        'selected_page_count' => count($oneBasedPages),
                        'selected_pages' => $oneBasedPages,
                        'source_page_count' => $sourcePageCount,
                        'output_size' => $outputSize,
                    ],
                    'document_id' => $document->id,
                    'status' => 'success',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json([
                'success' => true,
                'output_action' => 'download',
                'download_token' => $downloadToken,
                'download_name' => $downloadName,
                'selected_pages' => $oneBasedPages,
                'selected_page_count' => count($oneBasedPages),
                'source_page_count' => $sourcePageCount,
                'output_size' => $outputSize,
            ]);
        } finally {
            if (is_string($tokenOutputPath) && is_file($tokenOutputPath)) {
                @unlink($tokenOutputPath);
            }
            if (is_string($outputDir) && is_dir($outputDir)) {
                foreach (glob($outputDir . '/*') ?: [] as $path) {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
                @rmdir($outputDir);
            }
            $lock->release();
        }
    }

    private function normalizeSplitDownloadName(string $value): string
    {
        $name = str_replace(['\\', '/'], ' ', trim($value));
        $name = preg_replace('/[<>:"|?*\x00-\x1F]+/u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name), ". \t\n\r\0\x0B");
        if ($name === '') {
            return '';
        }

        $baseName = pathinfo($name, PATHINFO_FILENAME);
        $baseName = trim(mb_substr($baseName, 0, 236));
        if ($baseName === '') {
            return '';
        }

        return $baseName . '.pdf';
    }

    public function downloadConverted(Request $request)
    {
        $token = trim((string) $request->query('token', ''));
        if (!$token) {
            abort(400, 'Missing download token');
        }

        $conversion = DocumentConversion::query()->where('uuid', $token)->first();
        if ($conversion) {
            $document = Document::findOrFail($conversion->document_id);
            $this->authorizeDocumentConversionAccess($conversion, $document);
            abort_unless($conversion->status === DocumentConversion::STATUS_COMPLETED, 409, 'Conversion is not complete');

            if ($conversion->expires_at && now()->greaterThan($conversion->expires_at)) {
                if ($conversion->output_path) {
                    Storage::delete($conversion->output_path);
                }
                abort(410, 'Download link has expired');
            }
            if (! $conversion->output_path || ! Storage::exists($conversion->output_path)) {
                abort(404, 'File not found');
            }

            return response()->download(
                Storage::path($conversion->output_path),
                $conversion->download_name ?: 'converted-document',
                ['Content-Type' => $conversion->content_type ?: 'application/octet-stream']
            );
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
