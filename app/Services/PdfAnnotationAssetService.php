<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PdfAnnotationAssetService
{
    private const DISK = 'public';
    private const BASE_DIR = 'annotation-assets/documents';

    public function isImageBackedAnnotationType(mixed $type): bool
    {
        $normalized = strtolower(trim((string) $type));

        return in_array($normalized, ['image', 'signature'], true);
    }

    public function hasBlobPayload(array $annotation): bool
    {
        return $this->extractDataUrl($annotation) !== null;
    }

    public function normalizeForPersistence(Document $document, array $annotation): array
    {
        if (!$this->isImageBackedAnnotationType($annotation['type'] ?? null)) {
            return $annotation;
        }

        $normalized = $annotation;
        $annotationId = trim((string) ($normalized['id'] ?? ''));

        if ($annotationId === '') {
            $annotationId = 'ann_' . str_replace('-', '', (string) Str::uuid());
            $normalized['id'] = $annotationId;
        }

        $assetPath = $this->extractAssetPath($normalized);
        $dataUrl = $this->extractDataUrl($normalized);

        if ($dataUrl !== null) {
            $stored = $this->storeDataUrl(
                $document->id,
                $annotationId,
                $dataUrl,
                $normalized['fileName'] ?? null,
                $normalized['mimeType'] ?? null,
            );

            if ($stored !== null) {
                $assetPath = $stored['assetPath'];
                $normalized['mimeType'] = $stored['mimeType'];
                $normalized['fileName'] = $normalized['fileName'] ?? $stored['fileName'];
            } else {
                return $annotation;
            }
        }

        if (is_string($assetPath) && $assetPath !== '' && $this->assetAbsolutePath($assetPath) !== null) {
            $normalized['assetPath'] = $assetPath;
        } elseif ($dataUrl === null) {
            return $annotation;
        }

        if (array_key_exists('dataUrl', $normalized) || $dataUrl !== null) {
            $normalized['dataUrl'] = null;
        }

        if (
            array_key_exists('src', $normalized)
            || $this->looksLikeDataUrl($normalized['src'] ?? null)
            || $this->extractAssetPath(['src' => $normalized['src'] ?? null]) !== null
        ) {
            $normalized['src'] = null;
        }

        return $normalized;
    }

    public function enrichForClient(array $annotation): array
    {
        if (!$this->isImageBackedAnnotationType($annotation['type'] ?? null)) {
            return $annotation;
        }

        $assetPath = $this->extractAssetPath($annotation);
        if (!is_string($assetPath) || $assetPath === '') {
            return $annotation;
        }

        $annotation['assetPath'] = $assetPath;
        $annotation['src'] = $this->assetUrl($assetPath);
        $annotation['dataUrl'] = null;

        return $annotation;
    }

    public function enrichForPython(array $annotation): array
    {
        if (!$this->isImageBackedAnnotationType($annotation['type'] ?? null)) {
            return $annotation;
        }

        $assetPath = $this->extractAssetPath($annotation);
        if (!is_string($assetPath) || $assetPath === '') {
            return $annotation;
        }

        $absolutePath = $this->assetAbsolutePath($assetPath);
        if ($absolutePath === null) {
            return $annotation;
        }

        $annotation['assetPath'] = $assetPath;
        $annotation['assetFilePath'] = $absolutePath;
        $annotation['dataUrl'] = null;

        return $annotation;
    }

    public function assetUrl(string $assetPath): string
    {
        $assetRouteParameters = $this->documentAssetRouteParameters($assetPath);

        if ($assetRouteParameters !== null) {
            return route('documents.annotationAsset', $assetRouteParameters);
        }

        return Storage::disk(self::DISK)->url(ltrim($assetPath, '/'));
    }

    public function assetAbsolutePath(string $assetPath): ?string
    {
        $normalizedPath = ltrim($assetPath, '/');
        if ($normalizedPath === '') {
            return null;
        }

        $absolutePath = Storage::disk(self::DISK)->path($normalizedPath);

        return is_file($absolutePath) ? $absolutePath : null;
    }

    private function extractDataUrl(array $annotation): ?string
    {
        $candidates = [
            $annotation['dataUrl'] ?? null,
            $annotation['src'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($this->looksLikeDataUrl($candidate)) {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    private function extractAssetPath(array $annotation): ?string
    {
        foreach (['assetPath', 'imagePath'] as $field) {
            $candidate = $this->normalizeAssetPathValue($annotation[$field] ?? null);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        $src = $annotation['src'] ?? null;
        if (!is_string($src) || trim($src) === '' || $this->looksLikeDataUrl($src)) {
            return null;
        }

        $path = parse_url($src, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        return $this->normalizeAssetPathValue($path);
    }

    private function normalizeAssetPathValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $candidate = ltrim(trim($value), '/');
        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, 'storage/' . self::BASE_DIR . '/')) {
            return substr($candidate, strlen('storage/'));
        }

        if (str_starts_with($candidate, self::BASE_DIR . '/')) {
            return $candidate;
        }

        return null;
    }

    public function documentAssetRouteParameters(string $assetPath): ?array
    {
        $normalizedPath = ltrim(trim($assetPath), '/');

        if (!preg_match('#^' . preg_quote(self::BASE_DIR, '#') . '/(?<documentId>\d+)/(?<filename>[^/]+)$#', $normalizedPath, $matches)) {
            return null;
        }

        $documentId = (int) ($matches['documentId'] ?? 0);
        $filename = trim((string) ($matches['filename'] ?? ''));

        if ($documentId <= 0 || $filename === '' || basename($filename) !== $filename) {
            return null;
        }

        return [
            'document' => $documentId,
            'filename' => $filename,
        ];
    }

    private function looksLikeDataUrl(mixed $value): bool
    {
        return is_string($value) && str_starts_with(trim($value), 'data:image/');
    }

    private function storeDataUrl(
        int $documentId,
        string $annotationId,
        string $dataUrl,
        ?string $preferredFileName = null,
        ?string $preferredMimeType = null,
    ): ?array {
        $parsed = $this->parseDataUrl($dataUrl, $preferredMimeType);
        if ($parsed === null) {
            return null;
        }

        $mimeType = $parsed['mimeType'];
        $binary = $parsed['binary'];
        $extension = $this->extensionForMimeType($mimeType);
        $hash = substr(sha1($binary), 0, 16);
        $assetPath = sprintf(
            '%s/%d/%s_%s.%s',
            self::BASE_DIR,
            $documentId,
            $annotationId,
            $hash,
            $extension,
        );

        $directory = dirname($assetPath);
        $this->prepareWritableDirectory($directory);

        $disk = Storage::disk(self::DISK);
        $writeSucceeded = $disk->put($assetPath, $binary);
        $absolutePath = $disk->path($assetPath);

        if (!$writeSucceeded || !is_file($absolutePath)) {
            Log::warning('Failed to persist PDF annotation asset', [
                'document_id' => $documentId,
                'annotation_id' => $annotationId,
                'asset_path' => $assetPath,
                'directory' => $directory,
                'write_succeeded' => $writeSucceeded,
            ]);

            return null;
        }

        @chmod($absolutePath, 0664);

        $safeFileName = is_string($preferredFileName) && trim($preferredFileName) !== ''
            ? basename(trim($preferredFileName))
            : $annotationId . '.' . $extension;

        return [
            'assetPath' => $assetPath,
            'mimeType' => $mimeType,
            'fileName' => $safeFileName,
        ];
    }

    private function prepareWritableDirectory(string $relativeDirectory): void
    {
        $disk = Storage::disk(self::DISK);
        $relativeDirectory = trim($relativeDirectory, '/');

        if ($relativeDirectory === '' || $relativeDirectory === '.') {
            return;
        }

        $segments = explode('/', $relativeDirectory);
        $current = '';

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $current = $current === '' ? $segment : $current . '/' . $segment;

            if (!$disk->exists($current)) {
                $disk->makeDirectory($current);
            }

            $absolutePath = $disk->path($current);
            if (!is_dir($absolutePath)) {
                continue;
            }

            $this->syncPathOwnershipWithDiskRoot($absolutePath);
            @chmod($absolutePath, 0775);
        }
    }

    private function syncPathOwnershipWithDiskRoot(string $absolutePath): void
    {
        $diskRoot = storage_path('app/public');
        $owner = @fileowner($diskRoot);

        if (is_int($owner) && $owner >= 0) {
            @chown($absolutePath, $owner);
        }

        $group = @filegroup($diskRoot);
        if (is_int($group) && $group >= 0) {
            @chgrp($absolutePath, $group);
        }
    }

    private function parseDataUrl(string $dataUrl, ?string $preferredMimeType = null): ?array
    {
        if (!preg_match('/^data:(?<mime>[a-zA-Z0-9.+\/-]+)(?<meta>;[^,]*)?,(?<payload>.*)$/s', $dataUrl, $matches)) {
            return null;
        }

        $mimeType = strtolower(trim($matches['mime'] ?? ''));
        if (!str_starts_with($mimeType, 'image/')) {
            $mimeType = strtolower(trim((string) $preferredMimeType));
        }
        if (!str_starts_with($mimeType, 'image/')) {
            return null;
        }

        $meta = strtolower((string) ($matches['meta'] ?? ''));
        $payload = (string) ($matches['payload'] ?? '');

        if (str_contains($meta, ';base64')) {
            $binary = base64_decode($payload, true);
        } else {
            $binary = rawurldecode($payload);
        }

        if (!is_string($binary) || $binary === '') {
            return null;
        }

        return [
            'mimeType' => $mimeType,
            'binary' => $binary,
        ];
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            default => 'png',
        };
    }
}