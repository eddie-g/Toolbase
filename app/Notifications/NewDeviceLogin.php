<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an account signs in from an IP address it has not used before.
 */
class NewDeviceLogin extends Notification
{
    use Queueable;

    public function __construct(public string $ip, public ?string $userAgent, public \DateTimeInterface $at)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New sign-in to your '.config('app.name').' account')
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line('Your account was just signed in to from a new address.')
            ->line('When: '.$this->at->format('D, j M Y H:i T'))
            ->line('IP address: '.$this->ip)
            ->line('Browser: '.($this->userAgent ? mb_strimwidth($this->userAgent, 0, 120, '…') : 'unknown'))
            ->line('If this was you, there is nothing to do. If it was not, change your password now and log out your other sessions from your profile.')
            ->action('Open your settings', url('/portal/settings'));
    }
}
