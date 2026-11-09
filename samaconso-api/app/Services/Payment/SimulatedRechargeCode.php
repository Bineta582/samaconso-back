<?php

namespace App\Services\Payment;

use App\Models\Compteur;

/**
 * Code SIMULÉ (20 chiffres, format d'un code Woyofal) — il n'a AUCUNE valeur sur un vrai compteur.
 * En production, le code est délivré par le système de vente SENELEC : implémenter
 * RechargeCodeProvider avec l'API fournie par SENELEC / son prestataire de vente.
 */
class SimulatedRechargeCode implements RechargeCodeProvider
{
    public function emettre(Compteur $compteur, float $montantFcfa): string
    {
        $chiffres = '';
        for ($i = 0; $i < 20; $i++) {
            $chiffres .= random_int(0, 9);
        }

        return implode(' ', str_split($chiffres, 4));
    }
}
