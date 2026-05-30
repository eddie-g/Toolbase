<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdfState extends Model
{
    use HasFactory;

    protected $table = 'pdf_state';

    protected $fillable = [
        'document_id',
        'pdf_extraction_fitz_id',
        'user_id',
        'admin_id',
        'user_email',
        'session_id',
        'page_number',
        'annotation_data',
        'state',
        'alignment',
        'flagged',
        'flag_reason',
        'flag_images',
        'annotation_debug',
    ];

    protected $casts = [
        'annotation_data' => 'array',
        'flagged'         => 'boolean',
        'flag_images'     => 'array',
        'annotation_debug' => 'array',
    ];

    /**
     * Get the document that owns the PDF state.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function extraction(): BelongsTo
    {
        return $this->belongsTo(PdfExtractionFitz::class, 'pdf_extraction_fitz_id');
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
