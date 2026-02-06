<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiResponse extends Model
{
    protected $fillable = [
        'ai_request_id',
        'session',
        'document_id',
        'user_email',
        'response_payload',
        'parsed_sections',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'parsed_sections' => 'array',
    ];

    public function aiRequest(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class);
    }
}
