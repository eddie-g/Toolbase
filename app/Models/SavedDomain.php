<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedDomain extends Model
{
    protected $fillable = [
        'user_id',
        'domain',
        'is_available',
        'is_premium',
        'premium_price',
        'checked_at',
    ];

    protected $casts = [
        'is_available'  => 'boolean',
        'is_premium'    => 'boolean',
        'premium_price' => 'decimal:2',
        'checked_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
