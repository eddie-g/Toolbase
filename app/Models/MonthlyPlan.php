<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonthlyPlan extends Model
{
    protected $fillable = [
        'product_key',
        'name',
        'description',
        'price',
        'stripe_price_id',
        'features',
        'active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'features' => 'array',
        'active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    /**
     * Get only active plans.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
