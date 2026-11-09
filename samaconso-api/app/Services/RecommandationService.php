<?php

namespace App\Services;

use App\Enums\Priorite;
use App\Enums\TypeAlerte;
use App\Models\Alerte;
use App\Models\Compteur;
use App\Models\Prediction;
use App\Models\Recommandation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recommandations d'optimisation, déduites du profil réel de l'abonné (30 derniers jours),
 * classées par impact financier mensuel estimé (FCFA).
 *
 * ⚠ Les pourcentages d'économie ci-dessous sont des HEURISTIQUES de dimensionnement (ordres de grandeur
 * couramment admis), pas des mesures : ils sont regroupés dans REGLES pour être ajustés/justifiés dans le mémoire.
 */
class RecommandationService
{
    /** Part d'économie supposée par règle (fraction du coût mensuel de l'équipement concerné). */
    private const REGLES = [
        'climatiseur' => ['seuil_part' => 25.0, 'economie' => 0.12],
        'chauffe_eau' => ['seuil_part' => 10.0, 'economie' => 0.20],
        'eclairage' => ['seuil_part' => 8.0, 'economie' => 0.50],
        'refrigerateur' => ['facteur_exces' => 1.3, 'cycle_marche' => 0.40],
        'autres' => ['seuil_part' => 30.0, 'economie' => 0.05],
    ];

    public function __construct(
        private readonly ConsommationService $consommation,
        private readonly TariffService $tarif,
        private readonly CreditService $credit,
    ) {
    }

    /** @return Collection<int, Recommandation> */
    public function generer(Compteur $compteur, ?Prediction $prediction = null): Collection
    {
        $items = collect($this->regles($compteur, $prediction))
            ->sortByDesc('impact_estime_fcfa')->values();

        return DB::transaction(function () use ($compteur, $prediction, $items) {
            Recommandation::query()->where('compteur_id', $compteur->id)->delete();

            return $items->map(fn (array $r) => Recommandation::create([
                'compteur_id' => $compteur->id,
                'prediction_id' => $prediction?->id,
                'code' => $r['code'],
                'titre' => $r['titre'],
                'contenu' => $r['contenu'],
                'priorite' => $r['priorite'] ?? Priorite::depuisImpact($r['impact_estime_fcfa']),
                'impact_estime_fcfa' => round($r['impact_estime_fcfa']),
                'date_generation' => now(),
            ]));
        });
    }

    /** @return list<array{code: string, titre: string, contenu: string, impact_estime_fcfa: float, priorite?: Priorite}> */
    private function regles(Compteur $compteur, ?Prediction $prediction): array
    {
        $from = now()->subDays(30);
        $repartition = collect($this->consommation->repartition($compteur, $from, now()));
        $equipements = $compteur->equipements()->get()->keyBy('id');
        $prixKwh = $this->tarif->prixMarginal($this->consommation->energie($compteur, now()->startOfMonth(), now()), $compteur->categorieTarifaire());
        $out = [];

        foreach ($repartition as $ligne) {
            $eq = $ligne['equipement_id'] ? $equipements->get($ligne['equipement_id']) : null;
            $type = $eq?->type ?? ($ligne['equipement_id'] === null ? 'autres' : 'autre');
            $cout = $ligne['cout_fcfa'];

            switch ($type) {
                case 'climatiseur':
                    $r = self::REGLES['climatiseur'];
                    if ($ligne['pourcentage'] >= $r['seuil_part']) {
                        $out[] = [
                            'code' => "clim_reglage:{$eq->id}",
                            'titre' => "Optimiser {$eq->nom}",
                            'contenu' => sprintf("%s représente %.0f%% de votre consommation. Régler le thermostat à 24 °C, nettoyer les filtres et fermer portes et fenêtres peut réduire sa consommation d'environ %d%%.",
                                $eq->nom, $ligne['pourcentage'], $r['economie'] * 100),
                            'impact_estime_fcfa' => $cout * $r['economie'],
                        ];
                    }
                    break;

                case 'chauffe_eau':
                    $r = self::REGLES['chauffe_eau'];
                    if ($ligne['pourcentage'] >= $r['seuil_part']) {
                        $out[] = [
                            'code' => "chauffe_eau_programmation:{$eq->id}",
                            'titre' => "Programmer {$eq->nom}",
                            'contenu' => sprintf("%s pèse %.0f%% de votre consommation. Le programmer sur les plages de besoin réel (minuterie) évite de chauffer l'eau inutilement : économie estimée ≈ %d%%.",
                                $eq->nom, $ligne['pourcentage'], $r['economie'] * 100),
                            'impact_estime_fcfa' => $cout * $r['economie'],
                        ];
                    }
                    break;

                case 'eclairage':
                    $r = self::REGLES['eclairage'];
                    if ($ligne['pourcentage'] >= $r['seuil_part'] && ! str_contains(mb_strtolower($eq->nom.' '.$eq->description), 'led')) {
                        $out[] = [
                            'code' => "eclairage_led:{$eq->id}",
                            'titre' => 'Passer l\'éclairage en LED',
                            'contenu' => sprintf("L'éclairage représente %.0f%% de votre consommation. Remplacer les ampoules classiques par des LED divise sa consommation par 2 environ.", $ligne['pourcentage']),
                            'impact_estime_fcfa' => $cout * $r['economie'],
                        ];
                    }
                    break;

                case 'refrigerateur':
                case 'congelateur':
                    $r = self::REGLES['refrigerateur'];
                    $attendu = $eq->puissance_nominale * $r['cycle_marche'] * 24 * 30 / 1000; // kWh / 30 j
                    if ($attendu > 0 && $ligne['kwh'] > $attendu * $r['facteur_exces']) {
                        $exces = $ligne['kwh'] - $attendu;
                        $out[] = [
                            'code' => "froid_entretien:{$eq->id}",
                            'titre' => "Contrôler {$eq->nom}",
                            'contenu' => sprintf("%s consomme %.0f kWh sur 30 jours, soit nettement plus que la normale (≈ %.0f kWh). Dégivrez-le, vérifiez les joints de porte et éloignez-le des sources de chaleur.",
                                $eq->nom, $ligne['kwh'], $attendu),
                            'impact_estime_fcfa' => $exces * $prixKwh,
                        ];
                    }
                    break;

                case 'autres':
                    $r = self::REGLES['autres'];
                    if ($ligne['pourcentage'] >= $r['seuil_part']) {
                        $out[] = [
                            'code' => 'veilles_non_suivies',
                            'titre' => 'Réduire les veilles et équipements non suivis',
                            'contenu' => sprintf("%.0f%% de votre consommation n'est rattachée à aucun équipement suivi. Débranchez les appareils en veille (TV, box, chargeurs) et associez un capteur aux gros consommateurs pour les identifier.", $ligne['pourcentage']),
                            'impact_estime_fcfa' => $cout * $r['economie'],
                        ];
                    }
                    break;
            }
        }

        // Objectif mensuel
        $projection = $prediction ? $this->projectionDepuis($compteur, $prediction) : null;
        $objectif = $compteur->objectifMensuelKwh();
        if ($projection !== null) {
            if ($projection > $objectif) {
                $out[] = [
                    'code' => 'objectif_depasse',
                    'titre' => 'Objectif mensuel en risque',
                    'contenu' => sprintf('À ce rythme, vous atteindrez ≈ %.0f kWh ce mois-ci pour un objectif de %.0f kWh. Réduisez vos gros consommateurs pour rester dans le budget.', $projection, $objectif),
                    'impact_estime_fcfa' => ($projection - $objectif) * $prixKwh,
                ];
            } else {
                $out[] = [
                    'code' => 'objectif_ok',
                    'titre' => 'Objectif mensuel en bonne voie',
                    'contenu' => sprintf('Vous êtes en bonne voie pour rester sous votre objectif de %.0f kWh (projection : ≈ %.0f kWh).', $objectif, $projection),
                    'impact_estime_fcfa' => 0,
                ];
            }
        }

        // Pics de puissance répétés (risque de disjonction)
        $pics = Alerte::query()->where('compteur_id', $compteur->id)
            ->where('type', TypeAlerte::PicConsommation->value)->where('date_heure', '>=', $from)->count();
        if ($pics >= 2) {
            $out[] = [
                'code' => 'pics_puissance',
                'titre' => 'Éviter les pics de puissance',
                'contenu' => "Vous avez dépassé votre seuil de puissance {$pics} fois ces 30 derniers jours. Évitez d'utiliser simultanément vos appareils les plus puissants (four, chauffe-eau, climatiseur).",
                'impact_estime_fcfa' => 0,
                'priorite' => Priorite::Moyenne,
            ];
        }

        // Crédit Woyofal bientôt épuisé
        if ($compteur->type_compteur === 'woyofal') {
            $solde = $this->credit->solde($compteur);
            if ($solde['autonomie_jours'] !== null && $solde['autonomie_jours'] < 3) {
                $out[] = [
                    'code' => 'recharge_proche',
                    'titre' => 'Pensez à recharger votre compteur',
                    'contenu' => sprintf('Votre crédit estimé couvre environ %.1f jour(s). Rechargez avant la coupure.', $solde['autonomie_jours']),
                    'impact_estime_fcfa' => 0,
                    'priorite' => Priorite::Haute,
                ];
            }
        }

        return $out;
    }

    private function projectionDepuis(Compteur $compteur, Prediction $prediction): float
    {
        $finMois = now()->endOfMonth()->toDateString();
        $reste = 0.0;
        foreach ($prediction->details['daily'] ?? [] as $jour) {
            if ($jour['date'] <= $finMois) {
                $reste += $jour['kwh'];
            }
        }

        return $this->consommation->energie($compteur, now()->startOfMonth(), now()) + $reste;
    }
}
