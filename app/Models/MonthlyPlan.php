<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonthlyPlan extends Model
{
    public const BILLING_SUBSCRIPTION = 'subscription';

    public const BILLING_ONE_TIME = 'one_time';

    protected $fillable = [
        'product_key',
        'name',
        'description',
        'price',
        'billing_type',
        'duration_days',
        'unlocks_all_products',
        'included_credits',
        'stripe_price_id',
        'features',
        'active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'duration_days' => 'integer',
        'unlocks_all_products' => 'boolean',
        'included_credits' => 'decimal:2',
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

    /**
     * Plans that unlock every premium product, ordered cheapest first.
     */
    public function scopeAllAccess($query)
    {
        return $query->where('unlocks_all_products', true)->orderBy('price');
    }

    /**
     * A pass paid once, valid for `duration_days`, with no Stripe subscription.
     */
    public function isOneTime(): bool
    {
        return $this->billing_type === self::BILLING_ONE_TIME;
    }

    /**
     * Unit amount in cents for a one-time Stripe price_data line item.
     */
    public function priceInCents(): int
    {
        return (int) round(((float) $this->price) * 100);
    }
}
