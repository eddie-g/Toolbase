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

    /** Most signatures an account may keep. */
    public const PER_ACCOUNT_LIMIT = 20;

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
