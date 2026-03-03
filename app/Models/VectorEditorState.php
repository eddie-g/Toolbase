<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VectorEditorState extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'svg_content',
        'layers_data',
        'canvas_size',
    ];

    protected $casts = [
        'layers_data' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
