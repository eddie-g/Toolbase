<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PdfUploadTestCase extends Model
{
    protected $fillable = [
        'test_id',
        'pdf_upload_test_id',
        'annotation_id',
        'runtime_annotation_id',
        'page_index',
        'target_text',
        'test_comment',
        'test_saved_at',
    ];

    protected function casts(): array
    {
        return [
            'page_index' => 'integer',
            'test_saved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PdfUploadTestCase $testCase) {
            if (! $testCase->test_id) {
                $testCase->test_id = (string) Str::uuid();
            }
        });
    }

    public function uploadTest(): BelongsTo
    {
        return $this->belongsTo(PdfUploadTest::class, 'pdf_upload_test_id');
    }
}
