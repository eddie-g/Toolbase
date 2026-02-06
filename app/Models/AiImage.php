<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_response_id',
        'session',
        'document_id',
        'section_number',
        'prompt',
        'storage_type',
        'image_data',
        'mime_type',
        'width',
        'height',
    ];

    public function aiResponse()
    {
        return $this->belongsTo(AiResponse::class);
    }
}
