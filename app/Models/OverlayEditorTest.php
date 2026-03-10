<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OverlayEditorTest extends Model
{
    protected $table = 'tests_overlay_editor';

    protected $fillable = [
        'run_id',
        'test_type',
        'test_key',
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
        'artifacts',
    ];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'warnings' => 'array',
            'artifacts' => 'array',
        ];
    }
}
