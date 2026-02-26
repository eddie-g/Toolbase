<?php

namespace App\Services;

use App\Models\OpenAiBalanceLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class OpenAiBalanceService
{
    /**
     * Current locally-tracked balance (last balance_after value).
     */
    public static function current(): float
    {
        return (float) (OpenAiBalanceLedger::orderByDesc('id')->value('balance_after') ?? 0.0);
    }

    public static function totalSpent(): float
    {
        return (float) OpenAiBalanceLedger::where('type', 'debit')->sum('amount');
    }

    public static function totalCredited(): float
    {
        return (float) OpenAiBalanceLedger::where('type', 'credit')->sum('amount');
    }

    /**
     * Set balance to an absolute value, computing credit/debit delta.
     */
    public static function setBalance(float $newBalance, string $description = 'Manual balance set'): void
    {
        $current = self::current();
        $diff    = round($newBalance - $current, 6);

        if ($diff == 0) {
            return;
        }

        $type = $diff > 0 ? 'credit' : 'debit';
        self::addEntry($type, abs($diff), $newBalance, $description);
    }

    /**
     * Debit a charge from the tracked balance.
     */
    public static function debit(float $amount, string $model = '', ?int $logoRequestId = null): void
    {
        if ($amount <= 0) {
            return;
        }

        $newBalance = round(self::current() - $amount, 6);

        DB::table('openai_balance_ledger')->insert([
            'type'            => 'debit',
            'amount'          => round($amount, 6),
            'balance_after'   => $newBalance,
            'description'     => 'API charge: ' . ($model ?: 'openai'),
            'model'           => $model ?: null,
            'logo_request_id' => $logoRequestId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /**
     * Query OpenAI Usage API for a single date.
     * Returns total spend in USD for that date, or null on failure.
     *
     * Endpoint: GET /v1/usage?date=YYYY-MM-DD
     * Response: { data: [...], total_usage: float (in cents) }
     */
    public static function fetchDailyUsage(string $date): ?float
    {
        $key = config('services.openai.api_key');
        if (!$key) {
            return null;
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $key])
                ->timeout(10)
                ->get('https://api.openai.com/v1/usage', ['date' => $date]);

            if ($response->successful()) {
                $data = $response->json();
                // total_usage is in cents
                return isset($data['total_usage']) ? round((float) $data['total_usage'] / 100, 6) : 0.0;
            }
        } catch (\Exception) {
        }

        return null;
    }

    /**
     * Fetch usage for each day since a given date and return the total spend.
     * Stops early if a day returns null (API failure).
     */
    public static function fetchUsageSince(\Carbon\Carbon $since): ?float
    {
        $total = 0.0;
        $today = now()->startOfDay();
        $cursor = $since->copy()->startOfDay();

        while ($cursor->lte($today)) {
            $daily = self::fetchDailyUsage($cursor->format('Y-m-d'));
            if ($daily === null) {
                return null; // API failure
            }
            $total += $daily;
            $cursor->addDay();
        }

        return round($total, 6);
    }

    public static function recent(int $limit = 10): \Illuminate\Support\Collection
    {
        return OpenAiBalanceLedger::orderByDesc('id')->limit($limit)->get();
    }

    private static function addEntry(string $type, float $amount, float $balanceAfter, string $description): void
    {
        DB::table('openai_balance_ledger')->insert([
            'type'            => $type,
            'amount'          => round($amount, 6),
            'balance_after'   => round($balanceAfter, 6),
            'description'     => $description,
            'model'           => null,
            'logo_request_id' => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }
}
