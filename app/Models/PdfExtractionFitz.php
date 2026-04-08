<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdfExtractionFitz extends Model
{
    protected $table = 'pdf_extractions_fitz';

    protected $fillable = [
        'document_id',
        'user_email',
        'session_id',
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

    public function pages(): HasMany
    {
        return $this->hasMany(PdfExtractionPage::class, 'pdf_extraction_fitz_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(PdfExtractionBlock::class, 'pdf_extraction_fitz_id');
    }

    public function spans(): HasMany
    {
        return $this->hasMany(PdfExtractionSpan::class, 'pdf_extraction_fitz_id');
    }
}
