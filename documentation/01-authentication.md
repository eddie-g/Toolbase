# Authentication

Toolbase uses **Laravel Fortify** as the authentication backend, supplemented by **Laravel Sanctum** for API tokens and **Laravel Socialite** for Google OAuth. There are two separate guard contexts: `web` for regular users and `admin` for the Filament admin panel.

---

## Registration

New accounts are created at `POST /register` (`resources/views/auth/register.blade.php`). A user provides their name, email, and a confirmed password. The password is stored as a bcrypt hash via Laravel's `password` cast. The `credit_balance` column starts at `0`.

After registration, Laravel Fortify sends a signed verification email. Until that link is clicked, the user cannot access `/portal` — the `verified` middleware blocks unverified sessions.

---

## Email Verification

The `User` model implements `MustVerifyEmail`. The verification flow is entirely handled by Fortify: it sends the signed link, validates the token, marks `email_verified_at`, and redirects the user into the application. Unverified users hit a "check your email" holding page.

---

## Login

`POST /login` — Fortify validates credentials against the `users` table. On success the user is redirected to `/portal`. Repeated failed attempts are rate-limited by Fortify's built-in throttle. The remember-me token is supported.

---

## Password Reset

Users request a reset at `POST /forgot-password`. Fortify generates a signed, time-limited token and emails a reset link. The user clicks the link, submits `POST /reset-password` with a new confirmed password, and is redirected to `/login`. The `users` password broker is configured in `config/fortify.php`.

---

## Two-Factor Authentication (2FA)

2FA is enabled via the `TwoFactorAuthenticatable` trait (from Laravel Fortify) on the `User` model. Once enabled, the user must complete an OTP challenge after entering their password.

The `users` table stores the encrypted TOTP secret (`two_factor_secret`), encrypted backup codes (`two_factor_recovery_codes`), the confirmation timestamp (`two_factor_confirmed_at`), the preferred channel (`two_factor_channel`), and the phone number for SMS delivery (`phone`).

When `two_factor_channel` is `sms`, the `SmsService` sends the one-time code instead of requiring the user to use an authenticator app.

---

## Google OAuth

Clicking "Login with Google" hits `GET /auth/google`, which Socialite redirects to the Google consent screen. Google then calls back to `GET /auth/google/callback`, handled by `SocialAuthController`.

The controller looks up the returned email in the `users` table:

- **Found** — links `google_id` to the existing account if missing, then logs the user in. This means a user who registered by email can later link their Google account seamlessly just by signing in with it.
- **Not found** — creates a new `User` with a random password, sets `email_verified_at` to now (Google has already verified the email), and logs in.

For Google OAuth to work, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URL` must be set in `.env`. If any of these are missing, the controller redirects to `/login` with a clear error message instead of crashing.

---

## Admin Authentication

Admin accounts live in a separate `admins` table and authenticate on the `/admin` path using the `admin` guard. The Filament admin panel handles admin login independently of the user login flow. Admin accounts are not self-service — they are created directly in the database or via a seeder.

The `User` model restricts its Filament panel access to the `user` panel:

```php
public function canAccessPanel(Panel $panel): bool
{
    return $panel->getId() === 'user';
}
```

Several routes accept both guards (`auth:web,admin`) — for example, the domain saving endpoint — allowing admins to use user-facing features while authenticated on the admin guard.

---

## Middleware Reference

| Middleware | Purpose |
|---|---|
| `auth` | Require a logged-in web user |
| `auth:web,admin` | Accept either web user or admin |
| `verified` | Require email verification |
| `throttle:10,1` | Max 10 requests per minute (high-frequency endpoints) |
| `json.response` | Force JSON response format (logo generation endpoint) |
