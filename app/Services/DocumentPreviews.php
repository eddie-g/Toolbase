<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The thumbnail of a document's first page: a file under previews/, described
 * by preview_path, preview_image_mime_type, width, height and
 * preview_image_updated_at on the row. Rows written before this still carry
 * the image as base64 in preview_image; they are served as they are and moved
 * to a file the first time they are asked for.
 *
 * Row updates use the query builder so documents.updated_at, the order of the
 * documents list, does not change because a thumbnail was made.
 */
class DocumentPreviews
{
    public const DIRECTORY = 'previews';

    /** Columns a list needs to show a preview, without the image itself. */
    public const LIST_COLUMNS = ['preview_path', 'preview_image_mime_type', 'preview_image_width', 'preview_image_height', 'preview_image_updated_at'];

    public function store(Document $document, string $bytes, string $mimeType = 'image/jpeg', ?int $width = null, ?int $height = null): void
    {
        $extension = match ($mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $path = self::DIRECTORY.'/'.$document->id.'.'.$extension;
        Storage::put($path, $bytes);
        if ($document->preview_path && $document->preview_path !== $path) {
            Storage::delete($document->preview_path);
        }

        $this->write($document, [
            'preview_path' => $path,
            'preview_image' => null,
            'preview_image_mime_type' => $mimeType,
            'preview_image_width' => $width,
            'preview_image_height' => $height,
            'preview_image_updated_at' => now(),
        ]);
    }

    public function clear(Document $document): void
    {
        if ($document->preview_path) {
            Storage::delete($document->preview_path);
        }
        $this->write($document, [
            'preview_path' => null,
            'preview_image' => null,
            'preview_image_mime_type' => null,
            'preview_image_width' => null,
            'preview_image_height' => null,
            'preview_image_updated_at' => null,
        ]);
    }

    /** True when there is something to show; works on a row loaded with LIST_COLUMNS only. */
    public function has(Document $document): bool
    {
        return $document->preview_image_updated_at !== null;
    }

    /** The preview's address, versioned by when it was made so it can be cached hard. */
    public function url(Document $document): ?string
    {
        if (! $this->has($document)) {
            return null;
        }

        return route('documents.preview', $document).'?v='.$document->preview_image_updated_at->getTimestamp();
    }

    /** @return array{bytes:string, mime_type:string}|null */
    public function read(Document $document): ?array
    {
        if ($document->preview_path && Storage::exists($document->preview_path)) {
            return ['bytes' => Storage::get($document->preview_path), 'mime_type' => $document->preview_image_mime_type ?: 'image/jpeg'];
        }

        // A row from before previews were files: serve it, and move it.
        $legacy = DB::table('documents')->where('id', $document->id)->value('preview_image');
        $bytes = is_string($legacy) && $legacy !== '' ? base64_decode($legacy, true) : false;
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $mimeType = $document->preview_image_mime_type ?: 'image/jpeg';
        $this->moveLegacyToFile($document, $bytes, $mimeType);

        return ['bytes' => $bytes, 'mime_type' => $mimeType];
    }

    /** Used by read() and by documents:migrate-previews. Keeps the preview's original timestamp, so cached URLs stay valid. */
    public function moveLegacyToFile(Document $document, string $bytes, string $mimeType): void
    {
        $extension = $mimeType === 'image/png' ? 'png' : ($mimeType === 'image/webp' ? 'webp' : 'jpg');
        $path = self::DIRECTORY.'/'.$document->id.'.'.$extension;
        Storage::put($path, $bytes);
        $this->write($document, ['preview_path' => $path, 'preview_image' => null]);
    }

    private function write(Document $document, array $values): void
    {
        DB::table('documents')->where('id', $document->id)->update($values);
        $document->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }
}
