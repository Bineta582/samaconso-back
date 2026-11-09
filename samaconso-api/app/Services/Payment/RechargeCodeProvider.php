<?php

namespace App\Services\Payment;

use App\Models\Compteur;

/** Émet le code de recharge Woyofal correspondant à un paiement réussi. */
interface RechargeCodeProvider
{
    public function emettre(Compteur $compteur, float $montantFcfa): string;
}
