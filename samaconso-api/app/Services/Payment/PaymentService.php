<?php

namespace App\Services\Payment;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Models\Abonne;
use App\Models\Compteur;
use App\Models\MoyenDePaiement;
use App\Models\Paiement;
use App\Services\TariffService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Recharge Woyofal : création du paiement, débit via la passerelle, émission du code. */
class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $passerelle,
        private readonly RechargeCodeProvider $codes,
        private readonly TariffService $tarif,
    ) {
    }

    public function recharger(
        Abonne $abonne,
        Compteur $compteur,
        float $montant,
        MoyenPaiement $moyen,
        ?string $telephone,
        ?MoyenDePaiement $enregistre = null,
    ): Paiement {
        $min = (int) config('senelec.recharge_min_fcfa');
        $max = (int) config('senelec.recharge_max_fcfa');
        if ($montant < $min || $montant > $max) {
            throw ValidationException::withMessages([
                'montant' => "Le montant doit être compris entre {$min} et {$max} FCFA.",
            ]);
        }

        $frais = round($montant * (float) config("senelec.frais_paiement_pct.{$moyen->value}", 0) / 100, 2);

        // Les tranches Woyofal se cumulent par mois : on part des kWh déjà achetés ce mois-ci.
        $kwhAchetes = (float) Paiement::query()
            ->where('compteur_id', $compteur->id)->where('statut', StatutPaiement::Reussi->value)
            ->where('paye_le', '>=', now()->startOfMonth())->sum('kwh_estimes');
        $kwh = $this->tarif->kwhPourMontant($montant, $kwhAchetes, $compteur->categorieTarifaire());

        $paiement = Paiement::create([
            'reference' => 'SC-'.Str::upper(Str::random(12)),
            'abonne_id' => $abonne->id,
            'compteur_id' => $compteur->id,
            'moyen_paiement_id' => $enregistre?->id,
            'type' => 'recharge',
            'montant_fcfa' => $montant,
            'frais_fcfa' => $frais,
            'kwh_estimes' => $kwh,
            'moyen' => $moyen,
            'telephone' => $telephone,
            'statut' => StatutPaiement::EnAttente,
        ]);

        return $this->appliquer($paiement, $this->passerelle->debiter($paiement));
    }

    /**
     * Applique le résultat de la passerelle (réponse directe OU webhook).
     * Idempotent : un paiement déjà finalisé n'est jamais modifié (webhook rejoué).
     */
    public function appliquer(Paiement $paiement, ResultatPaiement $resultat): Paiement
    {
        return DB::transaction(function () use ($paiement, $resultat) {
            $p = Paiement::query()->lockForUpdate()->findOrFail($paiement->id);
            if ($p->statut !== StatutPaiement::EnAttente) {
                return $p;
            }

            match ($resultat->statut) {
                ResultatPaiement::REUSSI => $p->update([
                    'statut' => StatutPaiement::Reussi,
                    'paye_le' => now(),
                    'reference_prestataire' => $resultat->referencePrestataire,
                    'code_recharge' => $this->codes->emettre($p->compteur, $p->montant_fcfa),
                ]),
                ResultatPaiement::ECHOUE => $p->update([
                    'statut' => StatutPaiement::Echoue,
                    'reference_prestataire' => $resultat->referencePrestataire,
                    'message_erreur' => $resultat->erreur ?? 'Paiement refusé',
                ]),
                default => $p->update(['reference_prestataire' => $resultat->referencePrestataire]),
            };

            return $p->refresh();
        });
    }
}
