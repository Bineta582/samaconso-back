<?php

namespace App\Services;

use App\Enums\TypeAlerte;
use App\Enums\TypeCapteur;
use App\Models\Alerte;
use App\Models\Capteur;
use App\Models\Compteur;
use App\Models\Mesure;
use App\Models\SeuilAlerte;
use App\Notifications\AlerteDeclenchee;
use Illuminate\Support\Facades\Cache;

/**
 * Moteur d'alertes : compare mesures et consommations aux seuils de l'abonné,
 * crée l'alerte (table `alertes`) puis la notifie (base, email, SMS).
 * Un délai de « refroidissement » évite d'inonder l'abonné pour un même incident.
 */
class AlertEngine
{
    public function __construct(
        private readonly AnomalyDetector $anomalies,
        private readonly ConsommationService $consommation,
        private readonly CreditService $credit,
        private readonly RelayService $relais,
    ) {
    }

    /** À appeler après chaque mesure « live » enregistrée. */
    public function evaluerMesure(Mesure $m, Capteur $capteur): void
    {
        $capteur->loadMissing('compteur.abonne.user', 'equipement');
        $compteur = $capteur->compteur;
        $equipement = $capteur->equipement;
        $seuils = $this->seuils($compteur->abonne_id);
        $estGlobal = $capteur->type_capteur === TypeCapteur::Pzem->value;
        $cible = $equipement?->nom ?? 'votre installation';

        // 1) Pic de puissance (seuil propre à l'équipement, sinon seuil global sur le capteur principal)
        $cle = $equipement ? TypeAlerte::PicConsommation->value.':'.$equipement->id : null;
        $seuilPic = ($cle && isset($seuils[$cle])) ? $seuils[$cle]
            : ($estGlobal ? ($seuils[TypeAlerte::PicConsommation->value] ?? null) : null);
        if ($seuilPic !== null && $m->puissance !== null && $m->puissance > $seuilPic) {
            $this->lever($compteur, TypeAlerte::PicConsommation,
                sprintf('%s a dépassé le seuil de %d W (mesuré : %d W).', ucfirst($cible), $seuilPic, $m->puissance),
                $seuilPic, $m->puissance, $m, $capteur, 30);
        }

        // 2) Tension anormale (sur le capteur principal, en ignorant une coupure franche < 50 V)
        if ($estGlobal && $m->tension !== null && $m->tension > 50) {
            $max = $seuils[TypeAlerte::TensionHaute->value] ?? null;
            $min = $seuils[TypeAlerte::TensionBasse->value] ?? null;
            if ($max !== null && $m->tension > $max) {
                $this->lever($compteur, TypeAlerte::TensionHaute,
                    sprintf('Tension mesurée à %.0f V (seuil : %.0f V).', $m->tension, $max),
                    $max, $m->tension, $m, $capteur, 30);
            } elseif ($min !== null && $m->tension < $min) {
                $this->lever($compteur, TypeAlerte::TensionBasse,
                    sprintf('Tension mesurée à %.0f V (seuil minimal : %.0f V).', $m->tension, $min),
                    $min, $m->tension, $m, $capteur, 30);
            }
        }

        // 3) Comportement atypique par rapport aux habitudes de la même heure
        if ($info = $this->anomalies->evaluer($m)) {
            $this->lever($compteur, TypeAlerte::Anomalie,
                sprintf('%s consomme %d W, bien au-dessus de ses habitudes à cette heure (≈ %d W).',
                    ucfirst($cible), $m->puissance, $info['moyenne']),
                $info['seuil'], $m->puissance, $m, $capteur, 60);
        }

        // 4) Coupure automatique par relais et durée de fonctionnement continue
        if ($equipement) {
            if ($equipement->coupure_auto_w !== null && $m->puissance !== null && $m->puissance > $equipement->coupure_auto_w) {
                if (Cache::add("relais:coupure:{$equipement->id}", 1, now()->addMinutes(5))) {
                    $this->relais->commander($equipement, 'off', 'depassement_seuil');
                }
            }
            $this->verifierDuree($compteur, $capteur, $m, $seuils[TypeAlerte::DureeEquipement->value.':'.$equipement->id] ?? null);
        }
    }

    /** Fonctionnement continu d'un équipement (> 10 W) au-delà de N minutes. */
    private function verifierDuree(Compteur $compteur, Capteur $capteur, Mesure $m, ?float $minutes): void
    {
        if ($minutes === null) {
            return;
        }
        $cle = "duree:debut:{$capteur->id}";
        if (($m->puissance ?? 0) <= 10) {
            Cache::forget($cle);

            return;
        }
        $debut = Cache::add($cle, $m->date_heure->timestamp, now()->addDay()) ? $m->date_heure->timestamp : Cache::get($cle);
        if ($debut && ($m->date_heure->timestamp - $debut) / 60 >= $minutes) {
            $this->lever($compteur, TypeAlerte::DureeEquipement,
                sprintf('%s fonctionne sans interruption depuis plus de %d minutes.', ucfirst($capteur->equipement->nom), $minutes),
                $minutes, ($m->date_heure->timestamp - $debut) / 60, $m, $capteur, 240);
        }
    }

    /** Seuils journaliers (kWh, FCFA) et crédit Woyofal bas — appelé par le planificateur. */
    public function evaluerCompteur(Compteur $compteur): void
    {
        $compteur->loadMissing('abonne.user');
        $seuils = $this->seuils($compteur->abonne_id);
        $jour = now()->startOfDay();

        if (isset($seuils[TypeAlerte::ConsommationJournaliere->value])) {
            $kwh = $this->consommation->energie($compteur, $jour, now());
            if ($kwh > $seuils[TypeAlerte::ConsommationJournaliere->value]) {
                $this->lever($compteur, TypeAlerte::ConsommationJournaliere,
                    sprintf('Votre consommation du jour atteint %.1f kWh (seuil : %.1f kWh).', $kwh, $seuils[TypeAlerte::ConsommationJournaliere->value]),
                    $seuils[TypeAlerte::ConsommationJournaliere->value], $kwh, null, null, 720);
            }
        }

        if (isset($seuils[TypeAlerte::DepenseJournaliere->value])) {
            $cout = $this->consommation->cout($compteur, $jour, now());
            if ($cout > $seuils[TypeAlerte::DepenseJournaliere->value]) {
                $this->lever($compteur, TypeAlerte::DepenseJournaliere,
                    sprintf('Votre dépense du jour atteint %d FCFA (seuil : %d FCFA).', $cout, $seuils[TypeAlerte::DepenseJournaliere->value]),
                    $seuils[TypeAlerte::DepenseJournaliere->value], $cout, null, null, 720);
            }
        }

        if (isset($seuils[TypeAlerte::CreditBas->value]) && $compteur->type_compteur === 'woyofal') {
            $solde = $this->credit->solde($compteur);
            if ($solde['autonomie_jours'] !== null && $solde['autonomie_jours'] < $seuils[TypeAlerte::CreditBas->value]) {
                $this->lever($compteur, TypeAlerte::CreditBas,
                    sprintf('Crédit Woyofal bas : environ %d FCFA restants, soit %.1f jour(s) d\'autonomie. Pensez à recharger.',
                        $solde['solde_fcfa'], $solde['autonomie_jours']),
                    $seuils[TypeAlerte::CreditBas->value], $solde['autonomie_jours'], null, null, 720);
            }
        }
    }

    /** Marque hors ligne les capteurs silencieux et alerte selon le délai choisi par l'abonné. */
    public function evaluerCapteursHorsLigne(): int
    {
        $limite = now()->subMinutes((int) config('senelec.capteur_hors_ligne_minutes'));
        $n = 0;

        Capteur::query()
            ->whereIn('statut', ['actif', 'hors_ligne'])
            ->whereNotNull('last_seen_at')->where('last_seen_at', '<', $limite)
            ->with('compteur.abonne.user')
            ->each(function (Capteur $capteur) use (&$n) {
                if ($capteur->statut === 'actif') {
                    $capteur->update(['statut' => 'hors_ligne']);
                }
                $minutes = $this->seuils($capteur->compteur->abonne_id)[TypeAlerte::CapteurHorsLigne->value] ?? null;
                if ($minutes !== null && $capteur->last_seen_at->lt(now()->subMinutes($minutes))) {
                    $a = $this->lever($capteur->compteur, TypeAlerte::CapteurHorsLigne,
                        sprintf('Le capteur %s (%s) ne répond plus depuis %d minutes.', $capteur->device_id,
                            $capteur->equipement?->nom ?? $capteur->type_capteur, now()->timestamp / 60 - $capteur->last_seen_at->timestamp / 60),
                        $minutes, null, null, $capteur, 1440);
                    $n += $a ? 1 : 0;
                }
            });

        return $n;
    }

    /**
     * Seuils actifs de l'abonné : ['type' => valeur] ou ['type:equipement_id' => valeur].
     *
     * @return array<string, float>
     */
    public function seuils(int $abonneId): array
    {
        return Cache::remember("seuils:{$abonneId}", 60, function () use ($abonneId) {
            return SeuilAlerte::query()->where('abonne_id', $abonneId)->where('actif', true)->get()
                ->mapWithKeys(fn (SeuilAlerte $s) => [
                    $s->type->value.($s->equipement_id ? ':'.$s->equipement_id : '') => $s->valeur,
                ])->all();
        });
    }

    public static function oublierSeuils(int $abonneId): void
    {
        Cache::forget("seuils:{$abonneId}");
    }

    private function lever(
        Compteur $compteur,
        TypeAlerte $type,
        string $message,
        ?float $seuil,
        ?float $valeur,
        ?Mesure $mesure,
        ?Capteur $capteur,
        int $refroidissementMinutes,
    ): ?Alerte {
        $verrou = "alerte:{$compteur->id}:{$type->value}:".($capteur?->id ?? 'x');
        if (! Cache::add($verrou, 1, now()->addMinutes($refroidissementMinutes))) {
            return null; // déjà signalé récemment
        }

        $alerte = Alerte::create([
            'compteur_id' => $compteur->id,
            'capteur_id' => $capteur?->id,
            'mesure_id' => $mesure?->id,
            'type' => $type,
            'niveau' => $type->niveau(),
            'message' => $message,
            'seuil' => $seuil,
            'valeur' => $valeur,
            'date_heure' => now(),
            'statut' => Alerte::NON_LUE,
        ]);

        $compteur->loadMissing('abonne.user');
        $compteur->abonne->user->notify(new AlerteDeclenchee($alerte));

        return $alerte;
    }
}
