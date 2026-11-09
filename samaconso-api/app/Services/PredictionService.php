<?php

namespace App\Services;

use App\Models\Compteur;
use App\Models\Prediction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prédiction de la consommation à 7 et 30 jours.
 * Le calcul d'apprentissage est délégué au microservice Python (Random Forest Regressor, Scikit-learn).
 * Si le service est indisponible ou l'historique trop court, une moyenne par jour de semaine prend le relais
 * afin que l'API reste utilisable (exigence de fiabilité) — le champ `modele` l'indique.
 */
class PredictionService
{
    public const HISTORIQUE_MIN_JOURS = 14;

    public function __construct(
        private readonly ConsommationService $consommation,
        private readonly TariffService $tarif,
        private readonly RecommandationService $recommandations,
    ) {
    }

    /** @return array{7: Prediction, 30: Prediction} */
    public function generer(Compteur $compteur): array
    {
        $historique = $this->consommation->serieJournaliere($compteur, 120);
        $resultat = $this->appelerServiceMl($historique) ?? $this->secours($historique);

        $creees = DB::transaction(function () use ($compteur, $resultat) {
            $out = [];
            foreach ([7, 30] as $h) {
                $f = $resultat['forecasts'][$h];
                $out[$h] = Prediction::create([
                    'compteur_id' => $compteur->id,
                    'periode' => "{$h}_jours",
                    'date_prediction' => now(),
                    'date_debut' => now()->addDay()->toDateString(),
                    'date_fin' => now()->addDays($h)->toDateString(),
                    'consommation_prevue' => round($f['total_kwh'], 3),
                    'cout_prevu' => $this->coutPrevision($compteur, $f['daily']),
                    'modele' => $resultat['model'],
                    'precision' => $resultat['precision'],
                    'details' => [
                        'low_kwh' => $f['low_kwh'],
                        'high_kwh' => $f['high_kwh'],
                        'jours_historique' => $resultat['trained_on_days'] ?? count($historique),
                        'metriques' => $resultat['metrics'] ?? null,
                        'daily' => $f['daily'],
                    ],
                ]);
            }

            return $out;
        });

        // Les recommandations s'appuient sur la prédiction 30 jours fraîchement calculée.
        $this->recommandations->generer($compteur, $creees[30]);

        return $creees;
    }

    /** Projection de la consommation et du coût à la fin du mois en cours (réel + prévision). */
    public function projectionFinDeMois(Compteur $compteur): ?array
    {
        $p = Prediction::query()->where('compteur_id', $compteur->id)->where('periode', '30_jours')
            ->latest('date_prediction')->first();
        if (! $p) {
            return null;
        }

        $finMois = now()->endOfMonth()->toDateString();
        $reste = 0.0;
        foreach ($p->details['daily'] ?? [] as $jour) {
            if ($jour['date'] <= $finMois) {
                $reste += $jour['kwh'];
            }
        }
        $deja = $this->consommation->energie($compteur, now()->startOfMonth(), now());
        $total = $deja + $reste;

        return [
            'kwh' => round($total, 1),
            'cout_fcfa' => $this->tarif->cout($total, $compteur->categorieTarifaire()),
            'precision' => $p->precision,
            'modele' => $p->modele,
        ];
    }

    private function appelerServiceMl(array $historique): ?array
    {
        if (count($historique) < self::HISTORIQUE_MIN_JOURS) {
            return null;
        }

        try {
            $reponse = Http::baseUrl((string) config('services.ml.url'))
                ->timeout((int) config('services.ml.timeout'))->acceptJson()
                ->when(config('services.ml.token'), fn ($h, $token) => $h->withToken($token))
                ->post('/v1/forecast', [
                    'history' => array_map(fn ($date, $kwh) => ['date' => $date, 'kwh' => $kwh],
                        array_keys($historique), array_values($historique)),
                    'horizons' => [7, 30],
                ])->throw()->json();

            // Les clés JSON d'objet arrivent en chaînes ("7", "30") — on les normalise en entiers.
            $reponse['forecasts'] = [7 => $reponse['forecasts']['7'], 30 => $reponse['forecasts']['30']];

            return $reponse;
        } catch (Throwable $e) {
            Log::warning('Service ML indisponible — prédiction de secours utilisée', ['erreur' => $e->getMessage()]);

            return null;
        }
    }

    /** Secours : moyenne des 8 derniers mêmes jours de semaine (ou moyenne globale), intervalle ±15 %. */
    private function secours(array $historique): array
    {
        $parJourSemaine = [];
        foreach ($historique as $date => $kwh) {
            $parJourSemaine[Carbon::parse($date)->dayOfWeekIso][] = $kwh;
        }
        $moyenneGlobale = $historique ? array_sum($historique) / count($historique) : 0.0;

        $forecasts = [];
        foreach ([7, 30] as $h) {
            $daily = [];
            for ($i = 1; $i <= $h; $i++) {
                $jour = now()->addDays($i);
                $valeurs = array_slice($parJourSemaine[$jour->dayOfWeekIso] ?? [], -8);
                $kwh = $valeurs ? array_sum($valeurs) / count($valeurs) : $moyenneGlobale;
                $daily[] = ['date' => $jour->toDateString(), 'kwh' => round($kwh, 3),
                    'low' => round($kwh * 0.85, 3), 'high' => round($kwh * 1.15, 3)];
            }
            $total = array_sum(array_column($daily, 'kwh'));
            $forecasts[$h] = ['total_kwh' => round($total, 3), 'low_kwh' => round($total * 0.85, 3),
                'high_kwh' => round($total * 1.15, 3), 'daily' => $daily];
        }

        return ['model' => 'moyenne_mobile', 'precision' => null, 'trained_on_days' => count($historique),
            'metrics' => null, 'forecasts' => $forecasts];
    }

    /** Coût de la prévision jour par jour, en tenant compte des tranches du mois de chaque jour. */
    private function coutPrevision(Compteur $compteur, array $daily): float
    {
        $categorie = $compteur->categorieTarifaire();
        $cumul = [now()->format('Y-m') => $this->consommation->energie($compteur, now()->startOfMonth(), now())];
        $cout = 0.0;

        foreach ($daily as $jour) {
            $mois = substr($jour['date'], 0, 7);
            $avant = $cumul[$mois] ?? 0.0;
            $cout += $this->tarif->coutMarginal($avant, $jour['kwh'], $categorie);
            $cumul[$mois] = $avant + $jour['kwh'];
        }

        return round($cout, 2);
    }
}
