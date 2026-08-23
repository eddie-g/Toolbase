<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A signature stored against an account (NK_Dev_4).
 *
 * Distinct from the per-browser localStorage library: these follow the user
 * to any browser they sign in on.
 */
class SavedSignature extends Model
{
    protected $fillable = [
        'user_id',
        'admin_id',
        'name',
        'source_mode',
        'data_url',
        'composer',
        'width',
        'height',
    ];

    protected $casts = [
        'composer' => 'array',
        'width' => 'integer',
        'height' => 'integer',
    ];

    /**
     * How many signatures an account may keep.
     *
     * Standard accounts get a small allowance; an active pdf-editor
     * subscription raises it, matching how the rest of the editor gates
     * premium behaviour (see User::hasActiveSubscription).
     */
    public const STANDARD_ACCOUNT_LIMIT = 5;

    public const SUBSCRIBER_ACCOUNT_LIMIT = 20;

    /** Kept for callers that only need the ceiling. */
    public const PER_ACCOUNT_LIMIT = self::SUBSCRIBER_ACCOUNT_LIMIT;

    /**
     * The limit that applies to one owner.
     *
     * Admins are staff rather than customers, so they are not throttled by a
     * customer plan.
     *
     * @param  array{user_id: int|null, admin_id: int|null}  $ownership
     */
    public static function limitForOwner(array $ownership): int
    {
        if (($ownership['admin_id'] ?? null) !== null) {
            return self::SUBSCRIBER_ACCOUNT_LIMIT;
        }

        $userId = $ownership['user_id'] ?? null;
        if ($userId === null) {
            return self::STANDARD_ACCOUNT_LIMIT;
        }

        $user = User::query()->find($userId);

        return $user && $user->hasActiveSubscription('pdf-editor')
            ? self::SUBSCRIBER_ACCOUNT_LIMIT
            : self::STANDARD_ACCOUNT_LIMIT;
    }

    /**
     * Restrict to one account. A null/null owner matches nothing, so a guest
     * can never see or touch another account's signatures.
     *
     * @param  array{user_id: int|null, admin_id: int|null}  $ownership
     */
    public function scopeForOwner(Builder $query, array $ownership): Builder
    {
        $userId = $ownership['user_id'] ?? null;
        $adminId = $ownership['admin_id'] ?? null;

        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        if ($adminId !== null) {
            return $query->where('admin_id', $adminId);
        }

        return $query->whereRaw('1 = 0');
    }

    /** Shape sent to the signature modal. */
    public function toModalPayload(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => (string) $this->name,
            'sourceMode' => (string) $this->source_mode,
            'dataUrl' => (string) $this->data_url,
            'composer' => $this->composer,
            'width' => (int) $this->width,
            'height' => (int) $this->height,
            'updatedAt' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
