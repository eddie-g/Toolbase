<?php

namespace App\Listeners;

use App\Models\Admin;
use App\Models\AuthEvent;
use App\Models\User;
use App\Notifications\NewDeviceLogin;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The login audit trail: one auth_events row per event on either guard,
 * last_login_at / last_login_ip on the account, and an email when a user
 * signs in from an address they have not used before.
 */
class RecordAuthEvent
{
    public function handle(object $event): void
    {
        try {
            $this->record($event);
        } catch (\Throwable $e) {
            // Auditing must never break a sign-in.
            Log::warning('Auth event not recorded', ['event' => $event::class, 'error' => $e->getMessage()]);
        }
    }

    private function record(object $event): void
    {
        $request = request();
        $ip = $request?->ip();
        $agent = $request ? mb_strimwidth((string) $request->userAgent(), 0, 512, '') : null;

        [$name, $guard, $user, $email] = match (true) {
            $event instanceof Login => ['login', $event->guard, $event->user, $event->user->email ?? null],
            $event instanceof Logout => ['logout', $event->guard, $event->user, $event->user?->email],
            $event instanceof Failed => ['failed', $event->guard, $event->user, $event->credentials['email'] ?? $event->user?->email],
            $event instanceof Lockout => ['lockout', 'web', null, $event->request->input('email')],
            $event instanceof PasswordReset => ['password_reset', 'web', $event->user, $event->user->email ?? null],
            $event instanceof OtherDeviceLogout => ['other_devices_logout', $event->guard, $event->user, $event->user->email ?? null],
            default => [null, null, null, null],
        };

        if ($name === null || ! Schema::hasTable('auth_events')) {
            return;
        }

        AuthEvent::create([
            'guard' => $guard ?: 'web',
            'event' => $name,
            'user_id' => $user?->getAuthIdentifier(),
            'email' => $email ? mb_strtolower((string) $email) : null,
            'ip' => $ip,
            'user_agent' => $agent ?: null,
        ]);

        if ($event instanceof Login && ($user instanceof User || $user instanceof Admin)) {
            $this->touchLastLogin($user, $ip, $agent);
        }
    }

    private function touchLastLogin(User|Admin $user, ?string $ip, ?string $agent): void
    {
        $previousIp = $user->last_login_ip;
        $seenBefore = $user->last_login_at !== null;

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->saveQuietly();

        if ($user instanceof User && $seenBefore && $ip && $previousIp !== $ip) {
            $user->notify(new NewDeviceLogin($ip, $agent, now()));
        }
    }
}
