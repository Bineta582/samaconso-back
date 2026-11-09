<?php

namespace App\Services;

use App\Enums\StatutPaiement;
use App\Models\Compteur;
use App\Models\Paiement;

/**
 * Suivi estimé du crédit Woyofal : solde initial + recharges réussies − coût de l'énergie consommée.
 * C'est une ESTIMATION côté plateforme : le solde de référence reste celui affiché par le compteur.
 */
class CreditService
{
    public function __construct(
        private readonly TariffService $tarif,
        private readonly ConsommationService $consommation,
    ) {
    }

    public function solde(Compteur $c): array
    {
        $depuis = $c->solde_initial_at ?? $c->created_at;

        $recharges = (float) Paiement::query()
            ->where('compteur_id', $c->id)->where('statut', StatutPaiement::Reussi->value)
            ->where('paye_le', '>=', $depuis)->sum('montant_fcfa');

        $depenses = $this->consommation->cout($c, $depuis, now());
        $solde = max(0.0, round($c->solde_initial_fcfa + $recharges - $depenses, 2));

        $kwhMois = $this->consommation->energie($c, now()->startOfMonth(), now());
        $kwhRestants = $this->tarif->kwhPourMontant($solde, $kwhMois, $c->categorieTarifaire());
        $moyenneJour = $this->consommation->moyenneJournaliere($c, 7);

        $moisQuery = Paiement::query()->where('compteur_id', $c->id)
            ->where('statut', StatutPaiement::Reussi->value)->where('paye_le', '>=', now()->startOfMonth());

        return [
            'solde_fcfa' => $solde,
            'solde_kwh' => round($kwhRestants, 1),
            'autonomie_jours' => $moyenneJour > 0 ? round($kwhRestants / $moyenneJour, 1) : null,
            'consommation_moyenne_jour_kwh' => round($moyenneJour, 2),
            'depenses_mois_fcfa' => (float) (clone $moisQuery)->sum('montant_fcfa'),
            'recharges_mois' => (clone $moisQuery)->count(),
            'derniere_recharge' => Paiement::query()->where('compteur_id', $c->id)
                ->where('statut', StatutPaiement::Reussi->value)->latest('paye_le')->first(),
            'estimation' => true,
        ];
    }
}
