<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPdfMonthlyUsage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * How many documents someone may create. Accounts have a monthly allowance in
 * user_pdf_monthly_usages, guests a daily one in the rate limiter's cache
 * store. Both are taken with a single atomic step, so two uploads racing at
 * the limit cannot both get through. Admins are not limited.
 *
 * consume() returns null when the document may be created, or the refusal.
 * Call refund() if the document then could not be created after all.
 */
class UploadQuota
{
    /** @var array<int, callable> undo steps for the last successful consume() */
    private array $refunds = [];

    /** @return array{code:string, message:string}|null */
    public function consume(Request $request): ?array
    {
        $this->refunds = [];

        if (Auth::guard('admin')->check() && ! Auth::guard('web')->check()) {
            return null;
        }

        $user = Auth::guard('web')->user();

        return $user instanceof User ? $this->consumeForUser($user) : $this->consumeForGuest($request);
    }

    public function refund(): void
    {
        foreach ($this->refunds as $undo) {
            $undo();
        }
        $this->refunds = [];
    }

    private function consumeForUser(User $user): ?array
    {
        if ($user->hasActiveSubscription('pdf-editor')) {
            return null;
        }

        $limit = max(1, (int) config('pdf_editor.uploads.monthly_limit', 100));
        $month = now()->startOfMonth()->toDateString();
        try {
            $usage = UserPdfMonthlyUsage::firstOrCreate(
                ['user_id' => $user->id, 'month_start' => $month],
                ['uploads_count' => 0, 'actions_count' => 0, 'has_unlimited_actions' => false]
            );
        } catch (UniqueConstraintViolationException) {
            // Two first uploads of the month at once: the other request made the row.
            $usage = UserPdfMonthlyUsage::where('user_id', $user->id)->where('month_start', $month)->firstOrFail();
        }

        // The check and the increment are one statement: of two requests at
        // limit - 1, the database lets exactly one through.
        $taken = UserPdfMonthlyUsage::whereKey($usage->id)
            ->where('uploads_count', '<', $limit)
            ->increment('uploads_count');
        if ($taken !== 1) {
            return [
                'code' => 'monthly_upload_limit',
                'message' => "Monthly PDF upload limit reached ({$limit}).",
            ];
        }

        $this->refunds[] = static fn () => UserPdfMonthlyUsage::whereKey($usage->id)
            ->where('uploads_count', '>', 0)
            ->decrement('uploads_count');

        return null;
    }

    private function consumeForGuest(Request $request): ?array
    {
        $day = now()->toDateString();
        $session = $request->hasSession() ? $request->session()->getId() : 'none';
        $counters = [
            "upload-quota:{$day}:guest:{$session}:{$request->ip()}" => max(1, (int) config('pdf_editor.uploads.guest_daily_limit', 5)),
            "upload-quota:{$day}:ip:{$request->ip()}" => max(1, (int) config('pdf_editor.uploads.guest_daily_limit_per_ip', 20)),
        ];
        $store = Cache::store(config('cache.limiter') ?: null);
        $expires = now()->endOfDay()->addHour();

        foreach ($counters as $key => $limit) {
            // Increment first, then look: an atomic counter cannot be raced
            // past its limit the way read-then-write can.
            $store->add($key, 0, $expires);
            $count = (int) $store->increment($key);
            $this->refunds[] = static fn () => $store->decrement($key);
            if ($count > $limit) {
                $this->refund();

                return [
                    'code' => 'guest_upload_limit',
                    'message' => "You have reached today's limit of documents without an account. Sign in or create a free account to keep going.",
                ];
            }
        }

        return null;
    }
}
