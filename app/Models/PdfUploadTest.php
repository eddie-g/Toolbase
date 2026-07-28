<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdfUploadTest extends Model
{
    protected $fillable = [
        'uuid',
        'admin_id',
        'document_id',
        'original_name',
        'mime_type',
        'size_bytes',
        'sha256',
        'pdf_base64',
        'paragraph_grouping_enabled',
        'annotation_id',
        'runtime_annotation_id',
        'page_index',
        'target_text',
        'test_comment',
        'test_saved_at',
    ];

    protected $hidden = [
        'pdf_base64',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'paragraph_grouping_enabled' => 'boolean',
            'page_index' => 'integer',
            'test_saved_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(PdfUploadTestCase::class);
    }

    public function pdfContents(): string
    {
        $contents = base64_decode((string) $this->pdf_base64, true);

        if ($contents === false) {
            throw new \RuntimeException('The stored PDF upload test payload is invalid.');
        }

        return $contents;
    }
}
