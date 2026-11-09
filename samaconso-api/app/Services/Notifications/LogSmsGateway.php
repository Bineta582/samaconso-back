<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;

/** Passerelle de développement : écrit le SMS dans les logs au lieu de l'envoyer. */
class LogSmsGateway implements SmsGateway
{
    public function envoyer(string $numero, string $message): void
    {
        Log::info('[SMS simulé] '.$numero.' : '.$message);
    }
}
