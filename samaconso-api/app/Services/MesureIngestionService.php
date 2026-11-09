<?php

namespace App\Services;

use App\Models\Capteur;
use App\Models\Mesure;
use App\Support\MesureInvalide;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Point d'entrée unique des mesures reçues par HTTP (`POST /device/mesures[/batch]`).
 */
class MesureIngestionService
{
    public function __construct(
        private readonly AlertEngine $alertes,
        private readonly AgregationService $agregation,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return Mesure|null null si doublon (rejeu d'une mesure déjà enregistrée)
     *
     * @throws MesureInvalide
     */
    public function ingerer(Capteur $capteur, array $payload, bool $agregerSiAncien = true): ?Mesure
    {
        $d = $this->normaliser($payload);

        // Énergie cumulée absente : on l'intègre à partir de la puissance (kWh = W × Δt / 3 600 000).
        if ($d['energie'] === null) {
            $d['energie'] = $this->integrerEnergie($capteur, $d['date_heure'], $d['puissance']);
        }

        try {
            $mesure = Mesure::create([
                'capteur_id' => $capteur->id,
                'compteur_id' => $capteur->compteur_id,
                'equipement_id' => $capteur->equipement_id,
            ] + $d);
        } catch (UniqueConstraintViolationException) {
            return null; // doublon (capteur_id, date_heure)
        }

        $age = now()->timestamp - $d['date_heure']->timestamp;
        $this->majCapteur($capteur, $age);

        if ($age <= 600) {
            // Mesure « live » : détection d'alertes. Un échec ne doit jamais faire perdre la mesure.
            try {
                $this->alertes->evaluerMesure($mesure, $capteur);
            } catch (Throwable $e) {
                Log::error('Évaluation des alertes en échec', ['capteur' => $capteur->device_id, 'erreur' => $e->getMessage()]);
            }
        } elseif ($agregerSiAncien) {
            // Mesure rejouée depuis un tampon : l'agrégat de son heure doit être recalculé.
            $this->agregation->agreger($d['date_heure']->copy()->startOfHour(), $d['date_heure']->copy()->startOfHour()->addHour());
        }

        return $mesure;
    }

    /**
     * Lot de mesures (tampon local de l'ESP32 après coupure Wi-Fi).
     *
     * @param  list<array<string, mixed>>  $lignes
     * @return array{recues: int, inserees: int, doublons: int, rejetees: int, erreurs: list<string>}
     */
    public function ingererLot(Capteur $capteur, array $lignes): array
    {
        $bilan = ['recues' => count($lignes), 'inserees' => 0, 'doublons' => 0, 'rejetees' => 0, 'erreurs' => []];
        $min = null;
        $max = null;

        usort($lignes, fn ($a, $b) => ($a['timestamp'] ?? $a['ts'] ?? 0) <=> ($b['timestamp'] ?? $b['ts'] ?? 0));

        foreach ($lignes as $i => $ligne) {
            try {
                $m = $this->ingerer($capteur, (array) $ligne, agregerSiAncien: false);
                if ($m === null) {
                    $bilan['doublons']++;

                    continue;
                }
                $bilan['inserees']++;
                $min = $min === null || $m->date_heure->lt($min) ? $m->date_heure : $min;
                $max = $max === null || $m->date_heure->gt($max) ? $m->date_heure : $max;
            } catch (MesureInvalide $e) {
                $bilan['rejetees']++;
                if (count($bilan['erreurs']) < 20) {
                    $bilan['erreurs'][] = "ligne {$i}: ".$e->getMessage();
                }
            }
        }

        if ($min !== null) {
            $this->agregation->agreger($min->copy()->startOfHour(), $max->copy()->startOfHour()->addHour());
        }

        return $bilan;
    }

    /** Valide et convertit une charge utile (unités : V, A, W, kWh, Hz). */
    private function normaliser(array $p): array
    {
        $ts = $p['timestamp'] ?? $p['ts'] ?? null;
        try {
            if ($ts === null) {
                $date = now();
            } elseif (is_numeric($ts)) {
                $ts = (float) $ts;
                $date = Carbon::createFromTimestampUTC((int) ($ts > 100000000000 ? $ts / 1000 : $ts)); // s ou ms
            } else {
                $date = Carbon::parse((string) $ts);
            }
        } catch (Throwable) {
            throw new MesureInvalide('horodatage illisible');
        }

        if ($date->gt(now()->addMinutes(5))) {
            throw new MesureInvalide('horodatage dans le futur');
        }
        if ($date->lt(now()->subDays(7))) {
            throw new MesureInvalide('horodatage trop ancien (> 7 jours)');
        }

        $num = function (string $cle, float $min, float $max, array $alias = []) use ($p): ?float {
            $v = $p[$cle] ?? null;
            foreach ($alias as $a) {
                $v ??= $p[$a] ?? null;
            }
            if ($v === null || $v === '') {
                return null;
            }
            if (! is_numeric($v)) {
                throw new MesureInvalide("{$cle} non numérique");
            }
            $v = (float) $v;
            if ($v < $min || $v > $max) {
                throw new MesureInvalide("{$cle} hors plage ({$v})");
            }

            return $v;
        };

        $tension = $num('tension', 0, 500);
        $courant = $num('courant', 0, 200);
        $puissance = $num('puissance', 0, 50000);
        $energie = $num('energie', 0, 10_000_000);
        $frequence = $num('frequence', 0, 70);
        $pf = $num('facteur_puissance', 0, 1, ['pf']);

        if ($frequence !== null && $frequence > 0 && $frequence < 40) {
            throw new MesureInvalide("frequence hors plage ({$frequence})");
        }
        if ($tension === null && $courant === null && $puissance === null) {
            throw new MesureInvalide('aucune grandeur électrique fournie');
        }
        if ($puissance === null && $tension !== null && $courant !== null) {
            $puissance = round($tension * $courant * ($pf ?? 1.0), 2);
        }

        return [
            'date_heure' => $date,
            'tension' => $tension,
            'courant' => $courant,
            'puissance' => $puissance,
            'energie' => $energie,
            'frequence' => $frequence,
            'facteur_puissance' => $pf,
        ];
    }

    private function integrerEnergie(Capteur $capteur, Carbon $date, ?float $puissance): float
    {
        $prec = Mesure::query()->where('capteur_id', $capteur->id)
            ->where('date_heure', '<', $date)->orderByDesc('date_heure')->first();

        if (! $prec || $prec->energie === null) {
            return 0.0;
        }
        $dt = min(120, max(0, $date->timestamp - $prec->date_heure->timestamp)); // on borne les trous à 2 min
        $kwh = (($puissance ?? $prec->puissance ?? 0.0) * $dt) / 3_600_000;

        return round($prec->energie + $kwh, 6);
    }

    private function majCapteur(Capteur $capteur, int $age): void
    {
        if ($age > 600) {
            return; // mesure rejouée : ne dit rien de l'état courant du capteur
        }
        $vuIl = $capteur->last_seen_at?->timestamp ?? 0;
        if (now()->timestamp - $vuIl >= 30 || $capteur->statut === 'hors_ligne') {
            $capteur->forceFill([
                'last_seen_at' => now(),
                'statut' => $capteur->statut === 'inactif' ? 'inactif' : 'actif',
            ])->save();
        }
    }
}
