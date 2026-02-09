<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceReport extends Model
{
    protected $fillable = [
        'run_id',
        'filename',
        'description',
        'test_category',
        'section_name',
        'status',
        'conversion_success',
        'checks',
        'checks_passed',
        'checks_total',
        'compliance_status',
        'error',
        'warnings',
        'file_size_input',
        'file_size_output',
        'level',
    ];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'warnings' => 'array',
            'conversion_success' => 'boolean',
        ];
    }
}
