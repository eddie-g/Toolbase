<?php

namespace App\Auth;

use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Filament's login throttles five attempts a minute per IP. This adds a
 * per-account bucket (twenty failures an hour) so one admin account cannot
 * be guessed at from many addresses.
 */
class AdminLogin extends Login
{
    public function authenticate(): ?LoginResponse
    {
        $email = Str::lower(trim((string) ($this->data['email'] ?? '')));
        $key = 'admin-login-email:'.sha1($email);

        if ($email !== '' && RateLimiter::tooManyAttempts($key, 20)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'data.email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ]);
        }

        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            if ($email !== '') {
                RateLimiter::hit($key, 3600);
            }

            throw $exception;
        }

        if ($response !== null && $email !== '') {
            RateLimiter::clear($key);
        }

        return $response;
    }
}
