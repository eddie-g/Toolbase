<?php

namespace App\Models;

use App\Exceptions\InsufficientCreditBalanceException;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class Admin extends Authenticatable implements FilamentUser
{
    use Notifiable;

    protected $guard = 'admin';

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPPORT = 'support';

    /** Roles that may sign in to the admin panel at all. */
    public const PANEL_ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_SUPPORT];

    /** Roles that may open Horizon and other operational pages. */
    public const OPERATOR_ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'credit_balance',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'credit_balance' => 'decimal:4',
        ];
    }

    /**
     * Only rows with an explicit panel role may sign in; a row without a
     * role (or with an unknown one) is locked out until someone grants it.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole(...self::PANEL_ROLES);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array((string) $this->role, $roles, true);
    }

    public function isOperator(): bool
    {
        return $this->hasRole(...self::OPERATOR_ROLES);
    }

    /**
     * Deduct from admin's credit balance (thread-safe).
     */
    public function debitBalance(float $amount): void
    {
        DB::transaction(function () use ($amount) {
            $fresh = Admin::lockForUpdate()->findOrFail($this->id);
            $fresh->credit_balance = max(0, (float) $fresh->credit_balance - $amount);
            $fresh->save();
            $this->credit_balance = $fresh->credit_balance;
        });
    }

    public function debitBalanceIfSufficient(float $amount): void
    {
        DB::transaction(function () use ($amount) {
            $fresh = Admin::lockForUpdate()->findOrFail($this->id);
            $available = (float) $fresh->credit_balance;

            if ($available + 0.000001 < $amount) {
                throw new InsufficientCreditBalanceException($amount, $available);
            }

            $fresh->credit_balance = $available - $amount;
            $fresh->save();
            $this->credit_balance = $fresh->credit_balance;
        });
    }

    /**
     * Add to admin's credit balance (thread-safe).
     */
    public function topupBalance(float $amount): void
    {
        DB::transaction(function () use ($amount) {
            $fresh = Admin::lockForUpdate()->findOrFail($this->id);
            $fresh->credit_balance = (float) $fresh->credit_balance + $amount;
            $fresh->save();
            $this->credit_balance = $fresh->credit_balance;
        });
    }
}
