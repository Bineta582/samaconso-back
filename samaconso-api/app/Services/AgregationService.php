<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Agrège les mesures brutes (toutes les 5 s) en consommations horaires.
 * L'énergie d'une heure = somme des écarts positifs du compteur d'énergie cumulée du capteur.
 * Remise à zéro du compteur : détectée quand la valeur chute de plus de 50 % (on ajoute alors la valeur lue) ;
 * un recul plus faible (bruit de mesure, mesure arrivée dans le désordre) est ignoré, pour ne jamais
 * transformer une anomalie de 1 Wh en pic de plusieurs centaines de kWh.
 * L'opération est idempotente (UPSERT) : on peut la rejouer sur n'importe quelle plage.
 */
class AgregationService
{
    public function agreger(Carbon $from, Carbon $to): int
    {
        $from = $from->copy()->startOfHour();

        $sql = <<<'SQL'
INSERT INTO consommations_horaires
    (capteur_id, compteur_id, equipement_id, heure, energie_kwh, puissance_moy, puissance_max,
     tension_moy, courant_moy, frequence_moy, nb_mesures)
SELECT t.capteur_id, t.compteur_id, MAX(t.equipement_id), t.heure,
       COALESCE(SUM(t.delta), 0), AVG(t.puissance), MAX(t.puissance),
       AVG(t.tension), AVG(t.courant), AVG(t.frequence), COUNT(*)
FROM (
    SELECT m.capteur_id, m.compteur_id, m.equipement_id, m.date_heure,
           date_trunc('hour', m.date_heure) AS heure,
           m.puissance, m.tension, m.courant, m.frequence,
           CASE
               WHEN m.energie IS NULL THEN 0
               WHEN LAG(m.energie) OVER w IS NULL THEN 0
               WHEN m.energie >= LAG(m.energie) OVER w THEN m.energie - LAG(m.energie) OVER w
               WHEN m.energie < 0.5 * LAG(m.energie) OVER w THEN m.energie  -- remise à zéro du compteur
               ELSE 0                                                       -- léger recul (bruit, ordre) : ignoré
           END AS delta
    FROM mesures m
    WHERE m.date_heure >= ? AND m.date_heure < ?
    WINDOW w AS (PARTITION BY m.capteur_id ORDER BY m.date_heure)
) t
WHERE t.date_heure >= ?
GROUP BY t.capteur_id, t.compteur_id, t.heure
ON CONFLICT (capteur_id, heure) DO UPDATE SET
    equipement_id = EXCLUDED.equipement_id,
    energie_kwh = EXCLUDED.energie_kwh,
    puissance_moy = EXCLUDED.puissance_moy,
    puissance_max = EXCLUDED.puissance_max,
    tension_moy = EXCLUDED.tension_moy,
    courant_moy = EXCLUDED.courant_moy,
    frequence_moy = EXCLUDED.frequence_moy,
    nb_mesures = EXCLUDED.nb_mesures
SQL;

        // On lit une heure de plus en amont pour disposer de la valeur précédente (LAG)
        // de la toute première mesure de la plage.
        return DB::affectingStatement($sql, [$from->copy()->subHour(), $to, $from]);
    }
}
