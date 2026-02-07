<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuidedTemplate extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'preview_html',
        'defaults',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'defaults'  => 'array',
        'is_active' => 'boolean',
    ];
}
