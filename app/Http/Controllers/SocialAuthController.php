<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function redirectToGoogle()
    {
        if (!$this->hasConfiguredGoogleOAuth()) {
            return redirect('/login')->withErrors([
                'msg' => 'Google login is not configured. Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, and GOOGLE_REDIRECT_URL.',
            ]);
        }

        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        
            $user = User::where('email', $googleUser->getEmail())->first();

            if (!$user) {
                // Determine name components
                $name = $googleUser->getName() ?? $googleUser->getNickname() ?? 'User';
                
                $attributes = [
                    'name' => $name,
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                    'password' => bcrypt(uniqid()), // Random password
                    'email_verified_at' => now(),
                ];

                if (Schema::hasColumn('users', 'credit_balance')) {
                    $attributes['credit_balance'] = 0;
                }

                $user = User::create($attributes);
            } else {
                // Update google_id if it's missing (linking account by email)
                if (empty($user->google_id)) {
                    $user->update([
                        'google_id' => $googleUser->getId(),
                        'avatar' => $googleUser->getAvatar() ?? $user->avatar,
                    ]);
                }
            }

            Auth::login($user);

            // User OAuth login should land in the user portal, not admin panel.
            return redirect()->intended('/portal');
        } catch (\Exception $e) {
            return redirect('/login')->withErrors(['msg' => 'Google Login failed: ' . $e->getMessage()]);
        }
    }

    private function hasConfiguredGoogleOAuth(): bool
    {
        $values = [
            config('services.google.client_id'),
            config('services.google.client_secret'),
            config('services.google.redirect'),
        ];

        foreach ($values as $value) {
            if (!$this->isConfiguredOAuthValue($value)) {
                return false;
            }
        }

        return true;
    }

    private function isConfiguredOAuthValue(?string $value): bool
    {
        $value = trim((string) $value);

        if ($value === '') {
            return false;
        }

        $lowerValue = strtolower($value);

        return !str_starts_with($lowerValue, 'your-')
            && !str_contains($lowerValue, 'your_')
            && !str_contains($lowerValue, 'changeme')
            && !str_contains($lowerValue, 'placeholder');
    }
}
