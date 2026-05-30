<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    protected $fillable = [
        'user_id',
        'admin_id',
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

    public function notes()
    {
        return $this->hasMany(DocumentNote::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
