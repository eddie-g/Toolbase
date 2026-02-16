<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLogoRequest extends Model
{
    protected $fillable = [
        'user_id',
        'domain',
        'style',
        'prompt',
        'original_prompt',
        'status',
        'storage_type',
        'image_data',
        'mime_type',
        'width',
        'height',
        'image_urls',
        'fal_status_code',
        'error_message',
        'response_time_ms',
    ];

    protected $casts = [
        'image_urls' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
