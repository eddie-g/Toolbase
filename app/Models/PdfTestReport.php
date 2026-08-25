<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single stored result row from the Run PDF Tests page.
 *
 * Formerly OverlayEditorTest, backed by tests_overlay_editor. The table was
 * shared with the Run Overlay Tests and Run Shape Tests pages until both were
 * retired; PdfTestController is now its only writer, so both the model and the
 * table were renamed to say so.
 */
class PdfTestReport extends Model
{
    protected $table = 'pdf_test_reports';

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
