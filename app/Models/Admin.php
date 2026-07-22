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

    protected $fillable = [
        'name',
        'email',
        'password',
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
     * All rows in the admins table can access the Filament panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
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
