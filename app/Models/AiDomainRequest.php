<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDomainRequest extends Model
{
    protected $fillable = [
        'user_id',
        'prompt',
        'response',
        'model',
        'usage',
    ];

    protected $casts = [
        'response' => 'array',
        'usage' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
