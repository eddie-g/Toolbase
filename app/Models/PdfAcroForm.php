<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdfAcroForm extends Model
{
    use HasFactory;

    protected $table = 'pdf_acro_form';

    protected $fillable = [
        'document_id',
        'user_id',
        'sess_id',
        'page_num',
        'data',
        'state',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
