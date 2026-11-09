<?php

namespace App\Services;

use App\Models\Compteur;
use App\Models\ConsommationHoraire;
use App\Models\Mesure;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Lecture / analyse de la consommation à partir des agrégats horaires :
 * séries par période, répartition par équipement, comparaisons, coûts (grille SENELEC).
 */
class ConsommationService
{
    public const PERIODES = ['jour', 'semaine', 'mois', 'annee'];

    public const METRIQUES = [
        'energie' => ['sql' => 'SUM(energie_kwh)', 'unite' => 'kWh'],
        'puissance' => ['sql' => 'AVG(puissance_moy)', 'unite' => 'W'],
        'tension' => ['sql' => 'AVG(tension_moy)', 'unite' => 'V'],
        'courant' => ['sql' => 'AVG(courant_moy)', 'unite' => 'A'],
    ];

    public function __construct(private readonly TariffService $tarif)
    {
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} [début, fin exclue, pas SQL] */
    public function bornes(string $periode, Carbon $ref): array
    {
        $ref = $ref->copy();

        return match ($periode) {
            'jour' => [$ref->copy()->startOfDay(), $ref->copy()->startOfDay()->addDay(), 'hour'],
            'semaine' => [$ref->copy()->startOfWeek(), $ref->copy()->startOfWeek()->addWeek(), 'day'],
            'mois' => [$ref->copy()->startOfMonth(), $ref->copy()->startOfMonth()->addMonth(), 'day'],
            'annee' => [$ref->copy()->startOfYear(), $ref->copy()->startOfYear()->addYear(), 'month'],
            default => throw new InvalidArgumentException("Période invalide : {$periode}"),
        };
    }

    private function base(Compteur $c, Carbon $from, Carbon $to, ?int $equipementId = null): Builder
    {
        $q = ConsommationHoraire::query()
            ->where('compteur_id', $c->id)
            ->where('heure', '>=', $from)
            ->where('heure', '<', $to);

        return $equipementId
            ? $q->where('equipement_id', $equipementId)
            : $q->whereIn('capteur_id', $c->idsCapteursTotal());
    }

    public function energie(Compteur $c, Carbon $from, Carbon $to, ?int $equipementId = null): float
    {
        return (float) $this->base($c, $from, $to, $equipementId)->sum('energie_kwh');
    }

    /**
     * Coût de la période, calculé mois par mois : la position dans les tranches
     * dépend de la consommation déjà cumulée depuis le début du mois.
     * Pour un équipement : part du coût total proportionnelle à son énergie.
     */
    public function cout(Compteur $c, Carbon $from, Carbon $to, ?int $equipementId = null): float
    {
        $cat = $c->categorieTarifaire();
        $total = 0.0;
        $mois = $from->copy()->startOfMonth();

        while ($mois->lt($to)) {
            $moisFin = $mois->copy()->addMonth();
            $debut = $from->gt($mois) ? $from : $mois;
            $fin = $to->lt($moisFin) ? $to : $moisFin;

            if ($debut->lt($fin)) {
                $avant = $debut->gt($mois) ? $this->energie($c, $mois, $debut) : 0.0;
                $energieTotale = $this->energie($c, $debut, $fin);
                $coutSegment = $this->tarif->coutMarginal($avant, $energieTotale, $cat);

                if ($equipementId) {
                    $part = $energieTotale > 0 ? $this->energie($c, $debut, $fin, $equipementId) / $energieTotale : 0.0;
                    $coutSegment *= $part;
                }
                $total += $coutSegment;
            }
            $mois = $moisFin;
        }

        return round($total, 2);
    }

    /**
     * Série temporelle d'une métrique, avec les trous comblés.
     *
     * @return array{periode: string, du: string, au: string, metrique: string, unite: string, points: list<array{cle: string, label: string, valeur: float|null}>, total_kwh: float|null, cout_fcfa: float|null}
     */
    public function serie(Compteur $c, string $periode, Carbon $ref, ?int $equipementId = null, string $metrique = 'energie'): array
    {
        if (! isset(self::METRIQUES[$metrique])) {
            throw new InvalidArgumentException("Métrique invalide : {$metrique}");
        }
        [$from, $to, $pas] = $this->bornes($periode, $ref);
        $agg = self::METRIQUES[$metrique]['sql'];

        $rows = $this->base($c, $from, $to, $equipementId)
            ->selectRaw("date_trunc('{$pas}', heure) AS bucket, {$agg} AS valeur")
            ->groupByRaw("date_trunc('{$pas}', heure)")
            ->orderBy('bucket')
            ->get();

        $points = $this->construirePoints($rows, $from, $to, $pas, $metrique === 'energie');

        $estEnergie = $metrique === 'energie';

        return [
            'periode' => $periode,
            'du' => $from->toDateString(),
            'au' => $to->copy()->subSecond()->toDateString(),
            'metrique' => $metrique,
            'unite' => self::METRIQUES[$metrique]['unite'],
            'points' => $points,
            'total_kwh' => $estEnergie ? round(array_sum(array_column($points, 'valeur')), 3) : null,
            'cout_fcfa' => $estEnergie ? $this->cout($c, $from, $to, $equipementId) : null,
        ];
    }

    /**
     * Série de l'énergie (kWh) cumulée sur TOUS les compteurs (supervision administrateur).
     *
     * @return array{periode: string, du: string, au: string, unite: string, points: list<array{cle: string, label: string, valeur: float|null}>, total_kwh: float}
     */
    public function serieReseau(string $periode, Carbon $ref): array
    {
        [$from, $to, $pas] = $this->bornes($periode, $ref);

        $rows = ConsommationHoraire::query()
            ->join('capteurs', 'capteurs.id', '=', 'consommations_horaires.capteur_id')
            ->where('capteurs.type_capteur', 'PZEM-004T')
            ->where('consommations_horaires.heure', '>=', $from)
            ->where('consommations_horaires.heure', '<', $to)
            ->selectRaw("date_trunc('{$pas}', consommations_horaires.heure) AS bucket, SUM(consommations_horaires.energie_kwh) AS valeur")
            ->groupByRaw("date_trunc('{$pas}', consommations_horaires.heure)")
            ->orderBy('bucket')
            ->get();

        $points = $this->construirePoints($rows, $from, $to, $pas, true);

        return [
            'periode' => $periode,
            'du' => $from->toDateString(),
            'au' => $to->copy()->subSecond()->toDateString(),
            'unite' => 'kWh',
            'points' => $points,
            'total_kwh' => round(array_sum(array_column($points, 'valeur')), 3),
        ];
    }

    /** Les 24 dernières heures glissantes (carte « Consommation — 24 dernières heures »). */
    public function dernieres24Heures(Compteur $c): array
    {
        $to = now()->startOfHour()->addHour();
        $from = $to->copy()->subHours(24);

        $rows = $this->base($c, $from, $to)
            ->selectRaw("date_trunc('hour', heure) AS bucket, SUM(energie_kwh) AS valeur")
            ->groupByRaw("date_trunc('hour', heure)")
            ->orderBy('bucket')
            ->get();

        return $this->construirePoints($rows, $from, $to, 'hour', true);
    }

    /** Comble les trous d'une série agrégée (une entrée par pas, de $from à $to exclu). */
    private function construirePoints(iterable $rows, Carbon $from, Carbon $to, string $pas, bool $zeroParDefaut): array
    {
        $format = ['hour' => 'Y-m-d H:00', 'day' => 'Y-m-d', 'month' => 'Y-m'][$pas];
        $parCle = [];
        foreach ($rows as $row) {
            $parCle[Carbon::parse($row->bucket)->format($format)] = (float) $row->valeur;
        }

        $points = [];
        $curseur = $from->copy();
        while ($curseur->lt($to)) {
            $cle = $curseur->format($format);
            $points[] = [
                'cle' => $cle,
                'label' => match ($pas) {
                    'hour' => $curseur->format('H\\h'),
                    'day' => $curseur->format('d/m'),
                    'month' => $curseur->locale('fr')->isoFormat('MMM'),
                },
                'valeur' => isset($parCle[$cle]) ? round($parCle[$cle], 3) : ($zeroParDefaut ? 0.0 : null),
            ];
            $curseur = match ($pas) {
                'hour' => $curseur->addHour(),
                'day' => $curseur->addDay(),
                'month' => $curseur->addMonth(),
            };
        }

        return $points;
    }

    /** Répartition de la consommation par équipement (+ « Autres équipements »). */
    public function repartition(Compteur $c, Carbon $from, Carbon $to): array
    {
        $rows = ConsommationHoraire::query()
            ->where('compteur_id', $c->id)
            ->where('heure', '>=', $from)->where('heure', '<', $to)
            ->whereNotNull('equipement_id')
            ->selectRaw('equipement_id, SUM(energie_kwh) AS kwh')
            ->groupBy('equipement_id')
            ->get();

        $equipements = $c->equipements()->get()->keyBy('id');
        $total = $this->energie($c, $from, $to);
        $coutTotal = $this->cout($c, $from, $to);

        $items = [];
        $sommeEquipements = 0.0;
        foreach ($rows as $row) {
            $eq = $equipements->get($row->equipement_id);
            if (! $eq) {
                continue;
            }
            $kwh = (float) $row->kwh;
            $sommeEquipements += $kwh;
            $items[] = ['equipement_id' => $eq->id, 'nom' => $eq->nom, 'type' => $eq->type, 'kwh' => $kwh];
        }

        $reste = $total - $sommeEquipements;
        if ($reste > 0.001) {
            $items[] = ['equipement_id' => null, 'nom' => 'Autres équipements', 'type' => 'autre', 'kwh' => $reste];
        }

        $denominateur = max($total, $sommeEquipements);
        foreach ($items as &$item) {
            $part = $denominateur > 0 ? $item['kwh'] / $denominateur : 0.0;
            $item['pourcentage'] = round($part * 100, 1);
            $item['cout_fcfa'] = round($coutTotal * $part, 2);
            $item['kwh'] = round($item['kwh'], 3);
        }
        unset($item);

        usort($items, fn ($a, $b) => $b['kwh'] <=> $a['kwh']);

        return $items;
    }

    /** Compare deux périodes quelconques (énergie, coût, écart en %). */
    public function comparer(Compteur $c, Carbon $a1, Carbon $a2, Carbon $b1, Carbon $b2): array
    {
        $a = ['du' => $a1->toDateString(), 'au' => $a2->toDateString(),
            'kwh' => round($this->energie($c, $a1, $a2->copy()->endOfDay()), 3),
            'cout_fcfa' => $this->cout($c, $a1, $a2->copy()->endOfDay())];
        $b = ['du' => $b1->toDateString(), 'au' => $b2->toDateString(),
            'kwh' => round($this->energie($c, $b1, $b2->copy()->endOfDay()), 3),
            'cout_fcfa' => $this->cout($c, $b1, $b2->copy()->endOfDay())];

        return [
            'periode_a' => $a,
            'periode_b' => $b,
            'ecart_kwh' => round($b['kwh'] - $a['kwh'], 3),
            'ecart_pct' => $a['kwh'] > 0 ? round(($b['kwh'] - $a['kwh']) / $a['kwh'] * 100, 1) : null,
        ];
    }

    /** Histogramme des $nb derniers mois (le mois courant est marqué « en_cours »). */
    public function comparaisonMensuelle(Compteur $c, int $nb = 6): array
    {
        $out = [];
        $debut = now()->startOfMonth()->subMonths($nb - 1);
        for ($i = 0; $i < $nb; $i++) {
            $mois = $debut->copy()->addMonths($i);
            $fin = $mois->copy()->addMonth();
            $out[] = [
                'mois' => $mois->format('Y-m'),
                'label' => $mois->locale('fr')->isoFormat('MMM'),
                'kwh' => round($this->energie($c, $mois, $fin), 2),
                'cout_fcfa' => $this->cout($c, $mois, $fin),
                'en_cours' => $i === $nb - 1,
            ];
        }

        return $out;
    }

    /** Consommation et coût du mois en cours. */
    public function moisCourant(Compteur $c): array
    {
        $debut = now()->startOfMonth();
        $precedent = $debut->copy()->subMonth();
        // comparaison à la même durée écoulée le mois précédent
        $joursEcoules = max(1, (int) now()->day);
        $kwh = $this->energie($c, $debut, now());
        $kwhPrec = $this->energie($c, $precedent, $precedent->copy()->addDays($joursEcoules));

        return [
            'kwh' => round($kwh, 2),
            'cout_fcfa' => $this->cout($c, $debut, now()),
            'kwh_mois_precedent_meme_periode' => round($kwhPrec, 2),
            'variation_pct' => $kwhPrec > 0 ? round(($kwh - $kwhPrec) / $kwhPrec * 100, 1) : null,
        ];
    }

    /** Énergie par jour (uniquement les jours ayant des mesures) — entrée du modèle de prédiction. */
    public function serieJournaliere(Compteur $c, int $jours): array
    {
        $from = now()->subDays($jours)->startOfDay();
        $rows = $this->base($c, $from, now()->addMinute())
            ->selectRaw("date_trunc('day', heure) AS jour, SUM(energie_kwh) AS kwh, SUM(nb_mesures) AS n")
            ->groupByRaw("date_trunc('day', heure)")
            ->havingRaw('SUM(nb_mesures) > 0')
            ->orderBy('jour')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[Carbon::parse($row->jour)->toDateString()] = round((float) $row->kwh, 4);
        }

        // Le jour en cours est incomplet : on l'exclut pour ne pas biaiser l'apprentissage.
        unset($out[now()->toDateString()]);

        return $out;
    }

    public function moyenneJournaliere(Compteur $c, int $jours = 7): float
    {
        $serie = array_slice($this->serieJournaliere($c, $jours + 1), -$jours);

        return $serie ? array_sum($serie) / count($serie) : 0.0;
    }

    /** Dernières valeurs électriques reçues (affichage temps réel, rafraîchi toutes les 5 s côté Angular). */
    public function instantane(Compteur $c): array
    {
        $ids = $c->idsCapteursTotal();
        $mesures = Mesure::query()
            ->whereIn('capteur_id', $ids)
            ->where('date_heure', '>=', now()->subMinutes(2))
            ->orderByDesc('date_heure')
            ->get()
            ->unique('capteur_id');

        if ($mesures->isEmpty()) {
            return ['en_ligne' => false, 'puissance_w' => null, 'tension_v' => null, 'courant_a' => null,
                'frequence_hz' => null, 'date_heure' => null];
        }

        return [
            'en_ligne' => true,
            'puissance_w' => round($mesures->sum('puissance'), 1),
            'tension_v' => round((float) $mesures->avg('tension'), 1),
            'courant_a' => round($mesures->sum('courant'), 2),
            'frequence_hz' => round((float) $mesures->avg('frequence'), 2),
            'date_heure' => $mesures->max('date_heure')->toIso8601String(),
        ];
    }

    /** @return array<int, float> puissance instantanée (W) par équipement suivi par un capteur */
    public function instantaneParEquipement(Compteur $c): array
    {
        $ids = $c->capteurs()->whereNotNull('equipement_id')->pluck('id');

        return Mesure::query()
            ->whereIn('capteur_id', $ids)
            ->where('date_heure', '>=', now()->subMinutes(2))
            ->orderByDesc('date_heure')
            ->get()
            ->unique('capteur_id')
            ->mapWithKeys(fn (Mesure $m) => [$m->equipement_id => (float) $m->puissance])
            ->all();
    }

    /** Lignes horaires pour l'export (CSV / PDF). */
    public function lignesExport(Compteur $c, Carbon $from, Carbon $to): array
    {
        return $this->base($c, $from, $to)
            ->selectRaw('heure, SUM(energie_kwh) AS energie_kwh, AVG(puissance_moy) AS puissance_moy, AVG(tension_moy) AS tension_moy, AVG(courant_moy) AS courant_moy')
            ->groupBy('heure')
            ->orderBy('heure')
            ->get()
            ->map(fn ($r) => [
                'heure' => Carbon::parse($r->heure)->format('Y-m-d H:i'),
                'energie_kwh' => round((float) $r->energie_kwh, 4),
                'puissance_moy_w' => round((float) $r->puissance_moy, 1),
                'tension_moy_v' => round((float) $r->tension_moy, 1),
                'courant_moy_a' => round((float) $r->courant_moy, 2),
            ])->all();
    }
}
