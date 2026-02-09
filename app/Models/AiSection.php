<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSection extends Model
{
    protected $fillable = [
        'document_id',
        'ai_document_id',
        'session',
        'sections_data',
        'page_width',
        'page_height',
    ];

    public function aiDocument()
    {
        return $this->belongsTo(AiDocument::class);
    }


    protected $casts = [
        'sections_data' => 'array',
        'page_width' => 'decimal:2',
        'page_height' => 'decimal:2',
    ];
}
