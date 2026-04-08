<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdfExtractionBlock extends Model
{
    protected $table = 'pdf_extraction_blocks';

    protected $fillable = [
        'page_id',
        'pdf_extraction_fitz_id',
        'document_id',
        'page_number',
        'block_num',
        'source_key',
        'root_source_key',
        'text',
        'text_single_line',
        'text_lines',
        'line_bboxes',
        'left',
        'top',
        'width',
        'height',
        'bbox',
        'line_count',
        'font',
        'font_xref',
        'font_size',
        'font_weight',
        'italic',
        'underline',
        'hex_color',
        'line_height',
        'avg_line_height',
        'rotation',
        'direction',
        'has_mixed_styles',
        'block_data',
    ];

    protected $casts = [
        'text_lines' => 'array',
        'line_bboxes' => 'array',
        'bbox' => 'array',
        'direction' => 'array',
        'has_mixed_styles' => 'boolean',
        'italic' => 'boolean',
        'underline' => 'boolean',
        'block_data' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function extraction(): BelongsTo
    {
        return $this->belongsTo(PdfExtractionFitz::class, 'pdf_extraction_fitz_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(PdfExtractionPage::class, 'page_id');
    }

    public function spans(): HasMany
    {
        return $this->hasMany(PdfExtractionSpan::class, 'block_id');
    }
}
