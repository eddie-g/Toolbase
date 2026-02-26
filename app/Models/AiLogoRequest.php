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
        'result_data',
        'fal_status_code',
        'error_message',
        'response_time_ms',
        'is_favourited',
        'is_showcase',
    ];

    protected $casts = [
        'image_urls'   => 'array',
        'is_favourited' => 'boolean',
        'is_showcase'  => 'boolean',
        'seed_number'  => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
