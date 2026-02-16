<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class CreditTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balance_after',
        'service',
        'model_name',
        'description',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:6',
        'balance_after' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Deduct cost from user's balance and log the transaction.
     * Uses a DB transaction with row-level locking for safety.
     */
    public static function debit(
        int $userId,
        float $amount,
        string $service,
        ?string $modelName = null,
        ?string $description = null,
        ?array $metadata = null,
    ): self {
        return DB::transaction(function () use ($userId, $amount, $service, $modelName, $description, $metadata) {
            $user = User::lockForUpdate()->findOrFail($userId);
            $user->credit_balance = max(0, (float) $user->credit_balance - $amount);
            $user->save();

            return self::create([
                'user_id' => $userId,
                'type' => 'debit',
                'amount' => $amount,
                'balance_after' => $user->credit_balance,
                'service' => $service,
                'model_name' => $modelName,
                'description' => $description,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Add credit to user's balance (top-up or refund).
     */
    public static function topup(
        int $userId,
        float $amount,
        string $description = 'Credit top-up',
        ?array $metadata = null,
    ): self {
        return DB::transaction(function () use ($userId, $amount, $description, $metadata) {
            $user = User::lockForUpdate()->findOrFail($userId);
            $user->credit_balance = (float) $user->credit_balance + $amount;
            $user->save();

            return self::create([
                'user_id' => $userId,
                'type' => 'topup',
                'amount' => $amount,
                'balance_after' => $user->credit_balance,
                'service' => 'topup',
                'description' => $description,
                'metadata' => $metadata,
            ]);
        });
    }
}
