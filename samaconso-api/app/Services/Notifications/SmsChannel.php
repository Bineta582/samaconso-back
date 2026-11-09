<?php

namespace App\Services\Notifications;

use Illuminate\Notifications\Notification;

class SmsChannel
{
    public function __construct(private readonly SmsGateway $passerelle)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        $numero = $notifiable->routeNotificationFor('sms', $notification);
        if (! $numero) {
            return;
        }

        $this->passerelle->envoyer($numero, $notification->toSms($notifiable));
    }
}
