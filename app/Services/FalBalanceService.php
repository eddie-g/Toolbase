<?php

namespace App\Services;

use App\Models\FalBalanceLedger;
use Illuminate\Support\Facades\DB;

class FalBalanceService
{
    /**
     * Get the current fal.ai balance (last recorded balance_after value).
     */
    public static function current(): float
    {
        $latest = FalBalanceLedger::orderByDesc('id')->value('balance_after');

        return (float) ($latest ?? 0.0);
    }

    /**
     * Get the total amount spent (sum of all debits).
     */
    public static function totalSpent(): float
    {
        return (float) FalBalanceLedger::where('type', 'debit')->sum('amount');
    }

    /**
     * Get the total amount credited manually.
     */
    public static function totalCredited(): float
    {
        return (float) FalBalanceLedger::where('type', 'credit')->sum('amount');
    }

    /**
     * Record a manual balance top-up (sets balance to a specific value by
     * inserting a credit for the difference, or a debit if lower).
     *
     * @param  float  $newBalance  The new balance the user is setting
     * @param  string $description
     */
    public static function setBalance(float $newBalance, string $description = 'Manual balance set'): void
    {
        $current = self::current();
        $diff    = round($newBalance - $current, 6);

        if ($diff == 0) {
            return;
        }

        if ($diff > 0) {
            self::addEntry('credit', abs($diff), $newBalance, $description);
        } else {
            self::addEntry('debit', abs($diff), $newBalance, $description . ' (adjustment)');
        }
    }

    /**
     * Record a charge for a fal.ai API call.
     *
     * @param  float   $amount        Cost in USD
     * @param  string  $model         e.g. 'fal-ai/flux-2-flex'
     * @param  int|null $logoRequestId
     */
    public static function debit(float $amount, string $model = '', ?int $logoRequestId = null): void
    {
        if ($amount <= 0) {
            return;
        }

        $current    = self::current();
        $newBalance = round($current - $amount, 6);

        DB::table('fal_balance_ledger')->insert([
            'type'            => 'debit',
            'amount'          => round($amount, 6),
            'balance_after'   => $newBalance,
            'description'     => 'API charge: ' . ($model ?: 'fal.ai'),
            'model'           => $model ?: null,
            'logo_request_id' => $logoRequestId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /**
     * Get recent ledger entries (newest first).
     */
    public static function recent(int $limit = 10): \Illuminate\Support\Collection
    {
        return FalBalanceLedger::orderByDesc('id')->limit($limit)->get();
    }

    // ─── Internal ─────────────────────────────────────────────────────────────

    private static function addEntry(string $type, float $amount, float $balanceAfter, string $description): void
    {
        DB::table('fal_balance_ledger')->insert([
            'type'          => $type,
            'amount'        => round($amount, 6),
            'balance_after' => round($balanceAfter, 6),
            'description'   => $description,
            'model'         => null,
            'logo_request_id' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }
}
