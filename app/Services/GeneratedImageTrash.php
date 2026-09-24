<?php

namespace App\Services;

use App\Models\AiLogoRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Trash for generated images, one image of a batch at a time. Trashing hides
 * an image and can be undone for AiLogoRequest::TRASH_DAYS days; purging
 * deletes its files for good and is what the trash turns into afterwards.
 *
 * The image's url stays in image_urls so every other image keeps its index;
 * image_meta[index].purged_at is what hides it and makes its routes 404.
 */
class GeneratedImageTrash
{
    public function trash(AiLogoRequest $logo, int $index): void
    {
        $logo->updateImageMeta($index, ['trashed_at' => now()->toIso8601String()]);
    }

    public function restore(AiLogoRequest $logo, int $index): void
    {
        if ($logo->isImagePurged($index)) {
            return;
        }

        $logo->updateImageMeta($index, ['trashed_at' => null]);
    }

    public function purge(AiLogoRequest $logo, int $index): void
    {
        $urls = array_values((array) $logo->image_urls);
        $meta = $logo->imageMeta($index);

        $paths = array_filter([
            $this->publicPath($urls[$index] ?? null),
            $this->publicPath($meta['upscaled']['original_url'] ?? null),
        ]);
        foreach ($paths as $path) {
            if (Storage::disk('public')->exists($path) && !Storage::disk('public')->delete($path)) {
                Log::warning('Could not delete a purged generated image', ['logo_request_id' => $logo->id, 'index' => $index, 'path' => $path]);
            }
        }
        foreach (Storage::disk('public')->files('generated-image-previews/' . $logo->id) as $preview) {
            if (str_starts_with(basename($preview), $index . '-')) {
                Storage::disk('public')->delete($preview);
            }
        }

        // A purged image cannot stay on the public showcase.
        $showcase = array_values(array_diff((array) $logo->showcase_image_indexes, [$index]));
        if ($showcase !== array_values((array) $logo->showcase_image_indexes)) {
            $logo->forceFill([
                'showcase_image_indexes' => $showcase ?: null,
                'is_showcase' => $logo->is_showcase && $showcase !== [],
            ]);
        }

        $logo->updateImageMeta($index, [
            'trashed_at' => $meta['trashed_at'] ?? now()->toIso8601String(),
            'purged_at' => now()->toIso8601String(),
        ]);
    }

    /** Purge every image trashed before the cut-off. Returns how many. */
    public function purgeExpired(): int
    {
        $cutoff = now()->subDays(AiLogoRequest::TRASH_DAYS);
        $count = 0;

        AiLogoRequest::query()
            ->whereNotNull('image_meta')
            ->where('image_meta', 'like', '%trashed_at%')
            ->select(['id', 'image_urls', 'image_meta', 'is_showcase', 'showcase_image_indexes'])
            ->chunkById(200, function ($logos) use ($cutoff, &$count) {
                foreach ($logos as $logo) {
                    foreach ((array) $logo->image_meta as $index => $entry) {
                        $trashedAt = $entry['trashed_at'] ?? null;
                        if ($trashedAt && empty($entry['purged_at']) && now()->parse($trashedAt)->lt($cutoff)) {
                            $this->purge($logo, (int) $index);
                            $count++;
                        }
                    }
                }
            });

        return $count;
    }

    /** The public-disk path of a /storage/... url, or null for anything else. */
    private function publicPath(?string $url): ?string
    {
        $path = is_string($url) ? (parse_url($url, PHP_URL_PATH) ?: '') : '';

        return str_starts_with($path, '/storage/') ? substr($path, strlen('/storage/')) : null;
    }
}
