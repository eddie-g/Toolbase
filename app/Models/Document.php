<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'pdf_password_hash',
        'pdf_password_algorithm',
        'pdf_password_set_at',
    ];

    protected $casts = [
        'form_data' => 'array',
        'preview_image_updated_at' => 'datetime',
        'pdf_password_set_at' => 'datetime',
    ];

    public function pdfExtractionsFitz()
    {
        return $this->hasMany(PdfExtractionFitz::class);
    }

    public function activities()
    {
        return $this->hasMany(UserActivity::class);
    }

    public function conversions()
    {
        return $this->hasMany(DocumentConversion::class);
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

    public function pdfUploadTest(): HasOne
    {
        return $this->hasOne(PdfUploadTest::class);
    }
}
