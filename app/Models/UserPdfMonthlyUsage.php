<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPdfMonthlyUsage extends Model
{
    protected $fillable = [
        'user_id',
        'month_start',
        'uploads_count',
        'actions_count',
        'has_unlimited_actions',
    ];

    protected function casts(): array
    {
        return [
            'month_start' => 'date',
            'has_unlimited_actions' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
