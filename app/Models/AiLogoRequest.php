<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiLogoRequest extends Model
{
    protected $fillable = [
        'user_id',
        'domain',
        'style',
        'model',
        'output_format',
        'seed_number',
        'prompt',
        'original_prompt',
        'status',
        'storage_type',
        'image_data',
        'mime_type',
        'width',
        'height',
        'image_urls',
        'image_meta',
        'result_data',
        'fal_status_code',
        'error_message',
        'response_time_ms',
        'is_favourited',
        'is_showcase',
        'showcase_image_indexes',
    ];

    protected $casts = [
        'image_urls' => 'array',
        'image_meta' => 'array',
        'is_favourited' => 'boolean',
        'is_showcase' => 'boolean',
        'showcase_image_indexes' => 'array',
        'seed_number' => 'integer',
    ];

    /** Images trashed longer than this are deleted for good by logos:purge-trash. */
    public const TRASH_DAYS = 30;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * One image's own state (name, upscaled, trashed_at, purged_at), keyed by
     * its index in image_urls.
     *
     * @return array<string, mixed>
     */
    public function imageMeta(int $index): array
    {
        $meta = is_array($this->image_meta) ? $this->image_meta : [];

        return is_array($meta[(string) $index] ?? null) ? $meta[(string) $index] : [];
    }

    /** Merge $changes into one image's state; a null value removes that key. Saves. */
    public function updateImageMeta(int $index, array $changes): void
    {
        $meta = is_array($this->image_meta) ? $this->image_meta : [];
        $entry = array_filter(
            array_merge($this->imageMeta($index), $changes),
            fn ($value) => $value !== null,
        );

        if ($entry === []) {
            unset($meta[(string) $index]);
        } else {
            $meta[(string) $index] = $entry;
        }

        $this->forceFill(['image_meta' => $meta ?: null])->save();
    }

    /** Hidden from galleries: in the trash, or deleted for good. */
    public function isImageHidden(int $index): bool
    {
        $entry = $this->imageMeta($index);

        return !empty($entry['trashed_at']) || !empty($entry['purged_at']);
    }

    public function isImagePurged(int $index): bool
    {
        return !empty($this->imageMeta($index)['purged_at']);
    }
}
