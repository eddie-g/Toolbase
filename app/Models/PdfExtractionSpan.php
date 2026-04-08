<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdfExtractionSpan extends Model
{
    protected $table = 'pdf_extraction_spans';

    protected $fillable = [
        'page_id',
        'block_id',
        'pdf_extraction_fitz_id',
        'document_id',
        'page_number',
        'block_num',
        'line_num',
        'span_index',
        'text',
        'render_text',
        'suppress_drawn_underline',
        'has_drawn_underline',
        'font',
        'font_xref',
        'font_size',
        'font_weight',
        'color_value',
        'hex_color',
        'bold',
        'italic',
        'flags',
        'left',
        'top',
        'width',
        'height',
        'bbox',
        'ascender',
        'descender',
        'origin',
        'direction',
        'writing_mode',
        'rotation',
        'line_width',
        'render_type',
        'space_width',
        'uses_embedded_font',
        'embedded_font_name',
        'embedded_font_family',
        'embedded_font_xref',
        'is_link',
        'link_uri',
        'link_kind',
        'link_page',
        'source_content_ops',
        'span_data',
    ];

    protected $casts = [
        'bbox' => 'array',
        'origin' => 'array',
        'direction' => 'array',
        'source_content_ops' => 'array',
        'span_data' => 'array',
        'suppress_drawn_underline' => 'boolean',
        'has_drawn_underline' => 'boolean',
        'bold' => 'boolean',
        'italic' => 'boolean',
        'uses_embedded_font' => 'boolean',
        'is_link' => 'boolean',
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

    public function block(): BelongsTo
    {
        return $this->belongsTo(PdfExtractionBlock::class, 'block_id');
    }
}
