<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;

/**
 * Passerelle SMS générique : POST JSON {to, from, message} avec un jeton Bearer.
 * À adapter au fournisseur retenu (agrégateur SMS, API opérateur…) : le contrat exact
 * de chaque fournisseur diffère (URL, champs, authentification).
 */
class HttpSmsGateway implements SmsGateway
{
    public function envoyer(string $numero, string $message): void
    {
        Http::withToken((string) config('services.sms.token'))
            ->timeout(10)->acceptJson()
            ->post((string) config('services.sms.url'), [
                'to' => $numero,
                'from' => config('services.sms.sender'),
                'message' => $message,
            ])->throw();
    }
}
