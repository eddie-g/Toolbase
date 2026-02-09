<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use App\Services\SmsService;

class SendTwoFactorCodeListener
{
    public function __construct(
        protected TwoFactorAuthenticationProvider $provider,
        protected SmsService $smsService
    ) {}

    public function handle(TwoFactorAuthenticationChallenged $event): void
    {
        $user = $event->user;

        // Check if the user is configured for SMS channel and has a phone number
        if ($user->two_factor_channel === 'sms' && $user->phone) {
            
            // Generate the current TOTP code based on the user's secret
            // We must decrypt the secret as Fortify stores it encrypted
            $secret = decrypt($user->two_factor_secret);
            $code = $this->provider->getCurrentOtp($secret);
            
            $this->smsService->send(
                $user->phone, 
                "Your 2FA login code is: {$code}"
            );
        }
    }
}
