<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar',
        'phone',
        'two_factor_channel',
        'credit_balance',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'credit_balance' => 'decimal:4',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'user';
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    /**
     * Check if user has an active subscription for a given product.
     *
     * A plan that unlocks all products counts for every key. A subscription
     * past its period end (a lapsed week pass) no longer counts.
     */
    public function hasActiveSubscription(string $productKey): bool
    {
        return $this->subscriptions()
            ->current()
            ->whereHas('plan', fn ($q) => $q
                ->where('product_key', $productKey)
                ->orWhere('unlocks_all_products', true))
            ->exists();
    }

    /**
     * The user's current all-access plan row, if any (cheapest plan first).
     */
    public function currentAllAccessSubscription(): ?UserSubscription
    {
        return $this->subscriptions()
            ->current()
            ->whereHas('plan', fn ($q) => $q->where('unlocks_all_products', true))
            ->with('plan')
            ->get()
            ->sortBy(fn (UserSubscription $sub) => (float) $sub->plan->price)
            ->first();
    }
}
