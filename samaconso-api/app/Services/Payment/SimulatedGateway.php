<?php

namespace App\Services\Payment;

use App\Models\Paiement;
use Illuminate\Support\Str;

/**
 * Passerelle de SIMULATION pour le développement et la démonstration : aucun argent n'est débité.
 * Règle : un numéro finissant par « 0000 » échoue (pour tester le cas d'erreur), tout le reste réussit.
 * Pour un usage réel, implémenter PaymentGateway avec l'API du prestataire (contrat marchand requis).
 */
class SimulatedGateway implements PaymentGateway
{
    public function debiter(Paiement $paiement): ResultatPaiement
    {
        if ($paiement->telephone && str_ends_with(preg_replace('/\D/', '', $paiement->telephone), '0000')) {
            return new ResultatPaiement(ResultatPaiement::ECHOUE, null, 'Solde insuffisant (simulation)');
        }

        return new ResultatPaiement(ResultatPaiement::REUSSI, 'SIM-'.Str::upper(Str::random(10)));
    }
}
