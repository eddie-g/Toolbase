<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class UserActivity extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'category',
        'details',
        'document_id',
        'status',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * What each paid activity (a Word or Excel conversion) actually cost:
     * the amount of the debit its details point at, keyed by activity id.
     * Activities that charged nothing, and ones whose debit cannot be
     * found, are left out, so a quote is never shown as a charge.
     *
     * @param  iterable<UserActivity>  $activities
     * @return array<int, float>
     */
    public static function chargesFor(iterable $activities): array
    {
        $activities = Collection::make($activities)->keyBy('id');
        $transactionIds = $activities
            ->map(fn (UserActivity $activity) => (int) ($activity->details['billing_transaction_id'] ?? 0))
            ->filter();

        if ($transactionIds->isEmpty()) {
            return [];
        }

        $debits = CreditTransaction::query()
            ->whereIn('id', $transactionIds->unique()->values())
            ->where('type', 'debit')
            ->get(['id', 'user_id', 'amount'])
            ->keyBy('id');

        return $transactionIds
            ->map(function (int $transactionId, int $activityId) use ($debits, $activities) {
                $debit = $debits->get($transactionId);

                // Only the account's own debit counts.
                return $debit && (int) $debit->user_id === (int) $activities[$activityId]->user_id
                    ? (float) $debit->amount
                    : null;
            })
            ->filter(fn ($amount) => $amount !== null && $amount > 0)
            ->all();
    }
}
