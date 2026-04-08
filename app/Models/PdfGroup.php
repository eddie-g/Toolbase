<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdfGroup extends Model
{
    use HasFactory;

    protected $table = 'pdf_groups';

    protected $fillable = [
        'document_id',
        'pdf_extraction_fitz_id',
        'user_id',
        'admin_id',
        'user_email',
        'session_id',
        'page_number',
        'group_key',
        'root_source_key',
        'group_type',
        'group_bbox',
        'annotation_ids',
        'annotation_source_keys',
        'group_data',
        'state',
    ];

    protected $casts = [
        'group_bbox' => 'array',
        'annotation_ids' => 'array',
        'annotation_source_keys' => 'array',
        'group_data' => 'array',
    ];

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
