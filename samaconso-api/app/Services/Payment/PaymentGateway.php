<?php

namespace App\Services\Payment;

use App\Models\Paiement;

/** Contrat d'une passerelle de paiement (Orange Money, Wave, Free Money, carte…). */
interface PaymentGateway
{
    public function debiter(Paiement $paiement): ResultatPaiement;
}
