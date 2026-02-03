<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfExtractionFitz extends Model
{
    protected $table = 'pdf_extractions_fitz';

    protected $fillable = [
        'document_id',
        'pdf_filename',
        'total_pages',
        'total_words',
        'full_text',
        'extraction_data',
    ];

    protected $casts = [
        'extraction_data' => 'array',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
