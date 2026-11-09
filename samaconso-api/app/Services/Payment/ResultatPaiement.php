<?php

namespace App\Services\Payment;

final class ResultatPaiement
{
    public const REUSSI = 'reussi';
    public const ECHOUE = 'echoue';
    public const EN_ATTENTE = 'en_attente'; // validation asynchrone (USSD / webhook)

    public function __construct(
        public readonly string $statut,
        public readonly ?string $referencePrestataire = null,
        public readonly ?string $erreur = null,
    ) {
    }
}
