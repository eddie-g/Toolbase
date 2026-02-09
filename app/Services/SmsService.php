<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsService
{
    public function send(string $to, string $message): void
    {
        // In a real application, you would use Twilio or another provider here.
        // For now, we will log the message.
        
        Log::info("SMS sent to {$to}: {$message}");
        
        // Example Twilio Implementation:
        /*
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from = config('services.twilio.from');
        
        $client = new \Twilio\Rest\Client($sid, $token);
        $client->messages->create($to, [
            'from' => $from,
            'body' => $message
        ]);
        */
    }
}
