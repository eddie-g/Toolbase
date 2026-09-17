<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per sign-in, sign-out, failed attempt, lockout, password reset
 * or other-device logout, on either guard. Written by
 * App\Listeners\RecordAuthEvent; never updated.
 */
class AuthEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['guard', 'event', 'user_id', 'email', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
