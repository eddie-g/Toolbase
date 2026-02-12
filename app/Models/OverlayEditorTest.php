<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OverlayEditorTest extends Model
{
    protected $table = 'tests_overlay_editor';

    protected $fillable = [
        'run_id',
        'test_type',
        'filename',
        'description',
        'test_category',
        'section_name',
        'status',
        'checks',
        'checks_passed',
        'checks_total',
        'page_count',
        'file_size',
        'error',
        'warnings',
    ];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'warnings' => 'array',
        ];
    }
}
