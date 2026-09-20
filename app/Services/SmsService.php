<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Text messages through Twilio's HTTP API. The message body is never logged:
 * it carries one-time sign-in codes. Without TWILIO_SID / TWILIO_TOKEN /
 * TWILIO_FROM nothing is sent and send() says so, so callers can fall back
 * instead of pretending.
 */
class SmsService
{
    public function isConfigured(): bool
    {
        return filled(config('services.twilio.sid'))
            && filled(config('services.twilio.token'))
            && filled(config('services.twilio.from'));
    }

    /** True when Twilio accepted the message. */
    public function send(string $to, string $message): bool
    {
        if (! $this->isConfigured()) {
            Log::warning('SMS not sent: Twilio is not configured', ['to' => self::mask($to)]);

            return false;
        }

        $sid = (string) config('services.twilio.sid');
        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, (string) config('services.twilio.token'))
                ->timeout(10)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => (string) config('services.twilio.from'),
                    'To' => $to,
                    'Body' => $message,
                ]);
        } catch (\Throwable $exception) {
            Log::error('SMS not sent: Twilio could not be reached', ['to' => self::mask($to), 'error' => $exception->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            // Twilio's error object names the problem without echoing the body.
            Log::error('SMS not sent: Twilio refused it', [
                'to' => self::mask($to),
                'status' => $response->status(),
                'code' => $response->json('code'),
            ]);

            return false;
        }

        return true;
    }

    /** "+1 555 010 1234" becomes "••••••1234": enough for support, useless to anyone reading logs. */
    public static function mask(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return str_repeat('•', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
