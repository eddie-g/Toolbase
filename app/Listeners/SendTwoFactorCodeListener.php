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
            // Fortify's provider can verify a code but not produce one; the engine under it can.
            $code = app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secret);
            
            // A code that cannot be texted (Twilio down or not configured)
            // goes to the account's e-mail address instead: the alternative
            // is an account nobody can sign in to.
            if (! $this->smsService->send($user->phone, "Your Netkit sign-in code is {$code}")) {
                \Illuminate\Support\Facades\Mail::raw(
                    "Your Netkit sign-in code is {$code}\n\nWe could not send it by text message this time. If you did not try to sign in, change your password.",
                    fn ($message) => $message->to($user->email)->subject('Your Netkit sign-in code')
                );
            }
        }
    }
}
