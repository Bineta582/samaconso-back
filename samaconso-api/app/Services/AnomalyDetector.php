<?php

namespace App\Services;

use App\Models\Mesure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Détection de pics anormaux : compare la puissance mesurée à l'historique du même capteur
 * sur la même heure de la journée (14 derniers jours). Méthode statistique simple, explicable
 * à un abonné : « bien au-dessus de vos habitudes à cette heure-ci ».
 */
class AnomalyDetector
{
    public const MIN_ECHANTILLONS = 5;
    public const SEUIL_ABSOLU_W = 200;

    /** Règle de décision pure (testable sans base de données). */
    public static function estAnormale(float $puissance, float $moyenne, float $ecartType, int $echantillons): bool
    {
        if ($echantillons < self::MIN_ECHANTILLONS || $puissance < self::SEUIL_ABSOLU_W) {
            return false;
        }
        // marge = 3 écarts-types, avec un plancher pour éviter les faux positifs sur un signal très stable
        $marge = 3 * max($ecartType, 0.10 * $moyenne, 20.0);

        return $puissance > $moyenne + $marge;
    }

    /** @return array{moyenne: float, seuil: float}|null null si la mesure est normale */
    public function evaluer(Mesure $mesure): ?array
    {
        if ($mesure->puissance === null) {
            return null;
        }
        $heure = (int) $mesure->date_heure->format('G');
        $base = Cache::remember(
            "anomalie:baseline:{$mesure->capteur_id}:{$heure}",
            3600,
            fn () => $this->baseline($mesure->capteur_id, $heure)
        );

        if (! self::estAnormale($mesure->puissance, $base['moyenne'], $base['ecart_type'], $base['n'])) {
            return null;
        }

        return ['moyenne' => $base['moyenne'], 'seuil' => $base['moyenne'] + 3 * max($base['ecart_type'], 0.10 * $base['moyenne'], 20.0)];
    }

    /** @return array{moyenne: float, ecart_type: float, n: int} */
    private function baseline(int $capteurId, int $heure): array
    {
        $row = DB::table('consommations_horaires')
            ->where('capteur_id', $capteurId)
            ->whereRaw('EXTRACT(HOUR FROM heure) = ?', [$heure])
            ->where('heure', '>=', now()->subDays(14))
            ->where('heure', '<', now()->startOfHour())
            ->selectRaw('AVG(puissance_moy) AS m, STDDEV_SAMP(puissance_moy) AS s, COUNT(*) AS n')
            ->first();

        return [
            'moyenne' => (float) ($row->m ?? 0),
            'ecart_type' => (float) ($row->s ?? 0),
            'n' => (int) ($row->n ?? 0),
        ];
    }
}
