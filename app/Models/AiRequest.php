<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiRequest extends Model
{
    protected $fillable = [
        'session',
        'email',
        'template',
        'prompt',
        'sections',
        'model',
    ];

    protected $casts = [
        'sections' => 'array',
    ];

    public function responses(): HasMany
    {
        return $this->hasMany(AiResponse::class);
    }
}
