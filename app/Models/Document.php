<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'user_id',
        'original_name',
        'path',
        'original_backup_path',
        'mime_type',
        'size_bytes',
        'preview_image',
        'preview_image_mime_type',
        'preview_image_width',
        'preview_image_height',
        'preview_image_updated_at',
        'mode',
        'template_type',
        'template_slug',
        'form_data',
    ];

    protected $casts = [
        'form_data' => 'array',
        'preview_image_updated_at' => 'datetime',
    ];

    public function pdfExtractionsFitz()
    {
        return $this->hasMany(PdfExtractionFitz::class);
    }

    public function activities()
    {
        return $this->hasMany(UserActivity::class);
    }
}
