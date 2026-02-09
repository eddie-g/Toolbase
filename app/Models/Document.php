<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'original_name',
        'path',
        'mime_type',
        'size_bytes',
        'mode',
        'template_type',
        'template_slug',
        'form_data',
    ];

    protected $casts = [
        'form_data' => 'array',
    ];

    public function pdfExtractionsFitz()
    {
        return $this->hasMany(PdfExtractionFitz::class);
    }
}
