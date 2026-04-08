<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdfExtractionPage extends Model
{
    protected $table = 'pdf_extraction_pages';

    protected $fillable = [
        'pdf_extraction_fitz_id',
        'document_id',
        'page_number',
        'width',
        'height',
        'word_count',
        'text',
        'drawn_box_rects',
        'widget_rects',
    ];

    protected $casts = [
        'drawn_box_rects' => 'array',
        'widget_rects' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function extraction(): BelongsTo
    {
        return $this->belongsTo(PdfExtractionFitz::class, 'pdf_extraction_fitz_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(PdfExtractionBlock::class, 'page_id');
    }

    public function spans(): HasMany
    {
        return $this->hasMany(PdfExtractionSpan::class, 'page_id');
    }
}
