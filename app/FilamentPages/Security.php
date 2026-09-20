<?php

namespace App\FilamentPages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Filament\Notifications\Notification;

class Security extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationGroup = 'Settings';
    
    // Point to the view we confirmed exists
    protected static string $view = 'filament.pages.security';

    public $showQrCode = false;
    public $showRecoveryCodes = false;
    public $code = '';
    
    // SMS Properties
    public $phone = '';
    public $smsCode = '';
    public $showSmsInput = false;
    public $showSmsVerify = false;

    public function mount()
    {
        $this->phone = Auth::user()->phone;
    }

    public function getTitle(): string
    {
        return 'Security Settings';
    }

    // Toggle to SMS setup
    public function setupSms()
    {
        $this->showSmsInput = true;
        $this->showQrCode = false;
    }

    public function sendSmsCode(\App\Services\SmsService $smsService)
    {
        $this->validate([
            'phone' => 'required|string|max:20',
        ]);

        // Three texts per ten minutes per account and per number: each one costs
        // money, and an unlimited form is a way to text-bomb someone else.
        foreach (['sms-verify-user:' . Auth::id(), 'sms-verify-phone:' . sha1($this->phone)] as $limiterKey) {
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($limiterKey, 3)) {
                Notification::make()->title('Too many codes requested')->body('Wait a few minutes before asking for another.')->danger()->send();

                return;
            }
            \Illuminate\Support\Facades\RateLimiter::hit($limiterKey, 600);
        }

        $code = random_int(100000, 999999);
        cache()->put('sms_verify_' . Auth::id(), $code, 300); // 5 minutes

        if (! $smsService->send($this->phone, "Your Netkit verification code is {$code}")) {
            cache()->forget('sms_verify_' . Auth::id());
            Notification::make()
                ->title('Text messages are not available right now')
                ->body('Use an authenticator app instead.')
                ->danger()
                ->send();

            return;
        }

        $this->showSmsVerify = true;

        Notification::make()
            ->title('Verification code sent')
            ->body('It is valid for five minutes.')
            ->success()
            ->send();
    }

    public function enableSmsTwoFactor()
    {
        $this->validate([
            'smsCode' => 'required|string',
        ]);

        $cachedCode = cache()->get('sms_verify_' . Auth::id());

        if ($cachedCode === null || ! hash_equals((string) $cachedCode, trim((string) $this->smsCode))) {
             Notification::make()
                 ->title('Invalid Code')
                 ->danger()
                 ->send();
             return;
        }

        $user = Auth::user();
        
        // Use Fortify's action to generate secrets if not exists
        // We reuse the standard action, but ignore the TOTP part for SMS channel
        if (! $user->two_factor_secret) {
            $enable = app(EnableTwoFactorAuthentication::class);
            $enable($user);
        }

        $user->forceFill([
            'phone' => $this->phone,
            'two_factor_channel' => 'sms',
            'two_factor_confirmed_at' => now(), // Auto-confirm since we verified phone
        ])->save();

        $this->showSmsInput = false;
        $this->showSmsVerify = false;
        $this->showRecoveryCodes = true;

        Notification::make()
            ->title('SMS Two-Factor Authentication Enabled')
            ->success()
            ->send();
    }

    public function enableTwoFactor(EnableTwoFactorAuthentication $enable)
    {
        $enable(Auth::user());
        Auth::user()->forceFill(['two_factor_channel' => 'app'])->save();
        $this->showQrCode = true;
        $this->showSmsInput = false;
    }

    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirm)
    {
        $user = Auth::user();
        
        try {
            $confirm($user, $this->code);
            $this->showQrCode = false;
            $this->showRecoveryCodes = true;
            Notification::make()
                ->title('Two-Factor Authentication Confirmed')
                ->success()
                ->send();
        } catch (\Exception $e) {
             Notification::make()
                 ->title('Invalid Code')
                 ->body('The provided two-factor authentication code was invalid.')
                 ->danger()
                 ->send();
        }
    }

    public function disableTwoFactor(DisableTwoFactorAuthentication $disable)
    {
        $disable(Auth::user());
        $this->showQrCode = false;
        $this->showRecoveryCodes = false;
        Notification::make()
            ->title('Two-Factor Authentication Disabled')
            ->success()
            ->send();
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generate)
    {
        $generate(Auth::user());
        $this->showRecoveryCodes = true;
        Notification::make()
            ->title('Recovery Codes Regenerated')
            ->success()
            ->send();
    }

    public function getUserProperty()
    {
        return Auth::user();
    }
}
