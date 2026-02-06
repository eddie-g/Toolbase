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
        'user_email',
        'session_id',
        'page_number',
        'annotation_data',
        'state',
    ];

    protected $casts = [
        'annotation_data' => 'array',
    ];

    /**
     * Get the document that owns the PDF state.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
