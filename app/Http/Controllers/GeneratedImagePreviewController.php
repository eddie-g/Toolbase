<?php

namespace App\Http\Controllers;

use App\Models\AiLogoRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class GeneratedImagePreviewController extends Controller
{
    public function __invoke(Request $request, AiLogoRequest $logoRequest, int $index): Response
    {
        $sourcePath = $this->resolveOwnedSourcePath($request, $logoRequest, $index);
        if (!$sourcePath || !Storage::disk('public')->exists($sourcePath)) {
            abort(404);
        }

        if (str_ends_with(strtolower($sourcePath), '.svg')) {
            return $this->original($request, $logoRequest, $index);
        }

        $modified = Storage::disk('public')->lastModified($sourcePath) ?: time();
        $thumbPath = sprintf(
            'generated-image-previews/%d/%d-%s.jpg',
            $logoRequest->id,
            $index,
            substr(sha1($sourcePath . '|' . $modified), 0, 16),
        );

        if (!Storage::disk('public')->exists($thumbPath)) {
            $this->createJpegPreview($sourcePath, $thumbPath);
        }

        return response()
            ->file(Storage::disk('public')->path($thumbPath), [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
    }

    public function original(Request $request, AiLogoRequest $logoRequest, int $index): Response
    {
        $sourcePath = $this->resolveOwnedSourcePath($request, $logoRequest, $index);
        if (!$sourcePath || !Storage::disk('public')->exists($sourcePath)) {
            abort(404);
        }

        $mimeType = Storage::disk('public')->mimeType($sourcePath) ?: 'application/octet-stream';

        return response()
            ->file(Storage::disk('public')->path($sourcePath), [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, max-age=3600',
            ]);
    }

    private function resolveOwnedSourcePath(Request $request, AiLogoRequest $logoRequest, int $index): ?string
    {
        $user = $request->user();
        $admin = Auth::guard('admin')->user();

        if (!$admin && (!$user || (int) $logoRequest->user_id !== (int) $user->id)) {
            abort(403);
        }

        $urls = array_values(array_filter((array) $logoRequest->image_urls));
        $sourceUrl = $urls[$index] ?? null;
        if (!is_string($sourceUrl) || trim($sourceUrl) === '') {
            return null;
        }

        if (str_starts_with($sourceUrl, 'data:image/')) {
            return $this->materializeDataUrl($logoRequest, $index, $sourceUrl);
        }

        return $this->publicDiskPathFromUrl($sourceUrl);
    }

    private function materializeDataUrl(AiLogoRequest $logoRequest, int $index, string $dataUrl): ?string
    {
        if (!preg_match('/^data:(image\/[a-z0-9.+-]+)(?:;charset=[^;]+)?(;base64)?,(.*)$/is', $dataUrl, $matches)) {
            return null;
        }

        $mime = strtolower($matches[1]);
        $isBase64 = !empty($matches[2]);
        $payload = $matches[3];
        $body = $isBase64 ? base64_decode($payload, true) : rawurldecode($payload);
        if (!is_string($body) || $body === '') {
            return null;
        }

        $extension = match ($mime) {
            'image/svg+xml' => 'svg',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        $safeDomain = $logoRequest->domain ? (Str::slug($logoRequest->domain) ?: 'image') : 'image';
        $relativePath = sprintf(
            'logos/%d/%d/%s-%d-%02d.%s',
            (int) $logoRequest->user_id,
            (int) $logoRequest->id,
            $safeDomain,
            (int) $logoRequest->id,
            $index,
            $extension,
        );

        Storage::disk('public')->put($relativePath, $body);

        $urls = array_values((array) $logoRequest->image_urls);
        $urls[$index] = '/storage/' . $relativePath;
        $logoRequest->forceFill([
            'image_urls' => $urls,
            'storage_type' => 'path',
        ])->save();

        return $relativePath;
    }

    private function publicDiskPathFromUrl(string $url): ?string
    {
        if (str_starts_with($url, 'data:')) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $path = $path ? urldecode($path) : $url;
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            return Str::after($path, 'storage/');
        }

        if (Storage::disk('public')->exists($path)) {
            return $path;
        }

        return null;
    }

    private function createJpegPreview(string $sourcePath, string $thumbPath): void
    {
        $absoluteSource = Storage::disk('public')->path($sourcePath);
        $info = @getimagesize($absoluteSource);
        if (!$info || empty($info[0]) || empty($info[1])) {
            abort(415);
        }

        [$width, $height] = $info;
        $mime = $info['mime'] ?? '';
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($absoluteSource),
            'image/png' => @imagecreatefrompng($absoluteSource),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absoluteSource) : false,
            default => false,
        };

        if (!$source) {
            abort(415);
        }

        $maxWidth = 360;
        $maxHeight = 270;
        $scale = min($maxWidth / $width, $maxHeight / $height, 1);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $preview = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($preview, 255, 255, 255);
        imagefill($preview, 0, 0, $white);
        imagecopyresampled($preview, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($preview, null, 72);
        $jpeg = ob_get_clean();

        imagedestroy($source);
        imagedestroy($preview);

        if (!is_string($jpeg) || $jpeg === '') {
            abort(500);
        }

        Storage::disk('public')->put($thumbPath, $jpeg);
    }
}
