<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlerteResource;
use App\Http\Resources\CompteurResource;
use App\Http\Resources\PredictionResource;
use App\Http\Resources\RecommandationResource;
use App\Models\Alerte;
use App\Models\Prediction;
use App\Models\Recommandation;
use App\Services\ConsommationService;
use App\Services\PredictionService;
use App\Services\TariffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Données agrégées des écrans « Accueil » et « Tableau de bord » de l'abonné. */
class DashboardController extends Controller
{
    public function __construct(
        private readonly ConsommationService $conso,
        private readonly PredictionService $predictions,
        private readonly TariffService $tarif,
    ) {
    }

    public function accueil(Request $request): JsonResponse
    {
        $c = $this->compteurParDefaut($request);
        $jour = now()->startOfDay();
        $hier = $jour->copy()->subDay();
        $kwhJour = $this->conso->energie($c, $jour, now());
        $kwhHier = $this->conso->energie($c, $hier, now()->subDay());

        $alertes = Alerte::query()->where('compteur_id', $c->id);

        return response()->json(['data' => [
            'compteur' => new CompteurResource($c),
            'instantane' => $this->conso->instantane($c),
            'aujourdhui' => [
                'kwh' => round($kwhJour, 2),
                'cout_fcfa' => $this->conso->cout($c, $jour, now()),
                'variation_vs_hier_pct' => $kwhHier > 0 ? round(($kwhJour - $kwhHier) / $kwhHier * 100, 1) : null,
            ],
            'consommation_24h' => $this->conso->dernieres24Heures($c),
            'alertes_recentes' => AlerteResource::collection((clone $alertes)->latest('date_heure')->limit(3)->get()),
            'alertes_non_lues' => (clone $alertes)->nonLues()->count(),
            'repartition' => array_slice($this->conso->repartition($c, now()->subDays(30), now()), 0, 4),
            'statut_systeme' => [
                'ok' => (clone $alertes)->nonLues()->where('niveau', 'critique')->doesntExist(),
                'derniere_mesure' => $this->conso->instantane($c)['date_heure'],
            ],
        ]]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $c = $this->compteurParDefaut($request);
        $mois = $this->conso->moisCourant($c);
        $objectif = $c->objectifMensuelKwh();
        $objectifProrata = $objectif * (now()->day / now()->daysInMonth);

        // Courbe kWh + coût journalier du mois (le coût tient compte de la progression dans les tranches).
        $serie = $this->conso->serie($c, 'mois', now());
        $cumul = 0.0;
        $evolution = array_map(function (array $p) use (&$cumul, $c) {
            $kwh = (float) $p['valeur'];
            $p['cout_fcfa'] = $this->tarif->coutMarginal($cumul, $kwh, $c->categorieTarifaire());
            $cumul += $kwh;

            return $p;
        }, $serie['points']);

        $p7 = Prediction::query()->where('compteur_id', $c->id)->where('periode', '7_jours')->latest('date_prediction')->first();
        $p30 = Prediction::query()->where('compteur_id', $c->id)->where('periode', '30_jours')->latest('date_prediction')->first();

        return response()->json(['data' => [
            'compteur' => new CompteurResource($c),
            'kpis' => [
                'consommation_mois_kwh' => $mois['kwh'],
                'variation_vs_mois_precedent_pct' => $mois['variation_pct'],
                'cout_mois_fcfa' => $mois['cout_fcfa'],
                'objectif_mensuel_kwh' => $objectif,
                // négatif = sous l'objectif (proratisé au jour courant)
                'ecart_objectif_pct' => $objectifProrata > 0 ? round(($mois['kwh'] - $objectifProrata) / $objectifProrata * 100, 1) : null,
                'projection_fin_mois' => $this->predictions->projectionFinDeMois($c),
            ],
            'evolution_mois' => $evolution,
            'comparaison_mensuelle' => $this->conso->comparaisonMensuelle($c, 6),
            'repartition' => $this->conso->repartition($c, now()->startOfMonth(), now()),
            'predictions' => [
                '7_jours' => $p7 ? new PredictionResource($p7) : null,
                '30_jours' => $p30 ? new PredictionResource($p30) : null,
            ],
            'recommandations' => RecommandationResource::collection(
                Recommandation::query()->where('compteur_id', $c->id)->orderByDesc('impact_estime_fcfa')->limit(3)->get()
            ),
        ]]);
    }
}
