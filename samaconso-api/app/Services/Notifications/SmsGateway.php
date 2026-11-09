<?php

namespace App\Services\Notifications;

interface SmsGateway
{
    public function envoyer(string $numero, string $message): void;
}
