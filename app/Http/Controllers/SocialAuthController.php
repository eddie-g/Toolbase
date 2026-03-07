<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function redirectToGoogle()
    {
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
                
                $user = User::create([
                    'name' => $name,
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                    'password' => bcrypt(uniqid()), // Random password
                    'email_verified_at' => now(),
                ]);
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
}
