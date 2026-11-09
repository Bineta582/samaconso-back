<?php

namespace Database\Seeders;

use App\Enums\MoyenPaiement;
use App\Enums\Profil;
use App\Enums\StatutPaiement;
use App\Enums\TypeAbonnement;
use App\Enums\TypeAlerte;
use App\Models\Abonne;
use App\Models\Alerte;
use App\Models\Capteur;
use App\Models\Compteur;
use App\Models\ConsommationHoraire;
use App\Models\Equipement;
use App\Models\Mesure;
use App\Models\MoyenDePaiement;
use App\Models\Paiement;
use App\Models\SeuilAlerte;
use App\Models\User;
use App\Services\PredictionService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Données de démonstration cohérentes avec les maquettes (Awa Diop, compteur SN-0245871) :
 * 90 jours de consommations horaires, quelques mesures brutes récentes, alertes, paiements.
 * Générateur déterministe (graine fixe) : mêmes données à chaque exécution.
 */
class DemoSeeder extends Seeder
{
    private const PROFILS = [
        // type => [puissance nominale W, emplacement, nom, capteur SCT ?]
        'climatiseur' => ['Climatiseur salon', 'Salon', 1500, true],
        'refrigerateur' => ['Réfrigérateur', 'Cuisine', 350, true],
        'four' => ['Four électrique', 'Cuisine', 2000, true],
        'machine_a_laver' => ['Machine à laver', 'Buanderie', 800, true],
        'eclairage' => ['Éclairage LED', 'Ensemble du logement', 120, true],
        'chauffe_eau' => ['Chauffe-eau', 'Salle de bain', 1800, false], // volontairement sans capteur
    ];

    public function run(): void
    {
        mt_srand(2026);
        $awa = $this->abonne('Diop', 'Awa', 'awa.diop@email.com', '+221770000000', 'SN-0245871', 'Cité Keur Gorgui, Dakar', 'Password123');
        $this->installationComplete($awa);

        // Autres abonnés (supervision administrateur) : compteur + capteur principal + historique simple.
        foreach ([
            ['Sow', 'Mamadou', 'mamadou.sow@email.com', 'SN-0198234', 4.2],
            ['Ndiaye', 'Fatou', 'fatou.ndiaye@email.com', 'SN-0312456', 1.6],
            ['Ba', 'Ousmane', 'ousmane.ba@email.com', 'SN-0287612', 0.9],
            ['Diallo', 'Khady', 'khady.diallo@email.com', 'SN-0356789', 2.1],
        ] as [$nom, $prenom, $email, $numero, $moyenneKw]) {
            $compteur = $this->abonne($nom, $prenom, $email, null, $numero, 'Dakar', 'Password123');
            $pzem = $this->capteur($compteur, 'esp32-'.strtolower($nom).'-pzem', 'PZEM-004T', 'Tableau général', null);
            $this->historiqueGlobal($pzem, $moyenneKw);
        }
        if ($ousmane = Compteur::where('numero_compteur', 'SN-0287612')->first()) {
            $ousmane->update(['statut' => 'maintenance']);
        }

        $this->command?->info('Démo : awa.diop@email.com / Password123');
    }

    private function abonne(string $nom, string $prenom, string $email, ?string $tel, string $numero, string $adresse, string $mdp): Compteur
    {
        $user = User::firstOrCreate(['email' => $email], [
            'nom' => $nom, 'prenom' => $prenom, 'telephone' => $tel, 'password' => $mdp,
            'profil' => Profil::Abonne, 'statut' => 'actif',
        ]);
        $abonne = Abonne::firstOrCreate(['user_id' => $user->id], ['type_abonnement' => TypeAbonnement::Menage]);
        foreach (TypeAlerte::seuilsParDefaut() as $type => $valeur) {
            SeuilAlerte::firstOrCreate(['abonne_id' => $abonne->id, 'type' => $type, 'equipement_id' => null], ['valeur' => $valeur, 'actif' => true]);
        }

        return Compteur::firstOrCreate(['numero_compteur' => $numero], [
            'abonne_id' => $abonne->id, 'type_compteur' => 'woyofal', 'adresse' => $adresse, 'statut' => 'actif',
            'date_installation' => '2025-03-12', 'solde_initial_fcfa' => 20000, 'solde_initial_at' => now()->subDays(30),
        ]);
    }

    private function capteur(Compteur $c, string $deviceId, string $type, ?string $emplacement, ?int $equipementId): Capteur
    {
        return Capteur::firstOrCreate(['device_id' => $deviceId], [
            'compteur_id' => $c->id, 'equipement_id' => $equipementId, 'type_capteur' => $type,
            'emplacement' => $emplacement, 'statut' => 'actif', 'date_installation' => '2025-03-12',
            'last_seen_at' => now(), 'api_key_hash' => Capteur::hacherCle('demo-key-'.$deviceId),
        ]);
    }

    /** Installation d'Awa : capteur global, équipements, capteurs SCT, relais, historique, alertes, paiements. */
    private function installationComplete(Compteur $c): void
    {
        $pzem = $this->capteur($c, 'esp32-awa-pzem', 'PZEM-004T', 'Tableau général', null);
        $equipements = [];
        $i = 1;
        foreach (self::PROFILS as $type => [$nom, $emplacement, $watts, $aCapteur]) {
            $eq = Equipement::firstOrCreate(['compteur_id' => $c->id, 'nom' => $nom], [
                'type' => $type, 'emplacement' => $emplacement, 'puissance_nominale' => $watts,
            ]);
            $equipements[$type] = $eq;
            if ($aCapteur) {
                $this->capteur($c, 'esp32-awa-sct'.$i++, 'SCT-013', $emplacement, $eq->id);
            }
        }

        // Relais du four : coupure automatique au-delà de 2 500 W.
        $relais = $this->capteur($c, 'esp32-awa-relais1', 'RELAIS', 'Cuisine', null);
        $equipements['four']->update(['relais_capteur_id' => $relais->id, 'coupure_auto_w' => 2500]);

        $this->historiqueInstallation($c, $pzem, $equipements);
        $this->mesuresRecentes($c, $pzem);
        $this->alertes($c, $pzem);
        $this->paiements($c);

        // Prédictions + recommandations (service ML, ou moyenne mobile de secours s'il est arrêté).
        try {
            app(PredictionService::class)->generer($c->fresh('abonne'));
        } catch (Throwable $e) {
            $this->command?->warn('Prédictions non générées : '.$e->getMessage());
        }
    }

    // --------------------------------------------------------------------------------------------

    /** Fraction de l'heure à pleine puissance, selon le type d'équipement, l'heure et le jour. */
    private function duty(string $type, int $h, int $dow): float
    {
        return match ($type) {
            'climatiseur' => ($h >= 12 && $h <= 16) ? 0.7 : (($h >= 20 || $h <= 5) ? 0.55 : 0.1),
            'refrigerateur' => 0.4,
            'four' => in_array($h, [7, 8]) ? 0.25 : (in_array($h, [12, 13]) ? 0.35 : (in_array($h, [19, 20]) ? 0.4 : 0.0)),
            'machine_a_laver' => ($dow >= 6 && $h >= 9 && $h <= 11) ? 0.5 : 0.0,
            'eclairage' => ($h >= 18 && $h <= 23) ? 0.8 : (($h >= 5 && $h <= 7) ? 0.4 : 0.05),
            'chauffe_eau' => in_array($h, [5, 6, 7]) ? 0.5 : (in_array($h, [18, 19, 20]) ? 0.4 : 0.05),
            default => 0.1,
        };
    }

    private function bruit(float $amplitude = 0.15): float
    {
        return 1 + (mt_rand(-1000, 1000) / 1000) * $amplitude;
    }

    private function historiqueInstallation(Compteur $c, Capteur $pzem, array $equipements): void
    {
        $lignes = [];
        $capteursSct = Capteur::query()->where('compteur_id', $c->id)->whereNotNull('equipement_id')->get()->keyBy('equipement_id');
        $fin = now()->startOfHour();

        for ($t = $fin->copy()->subDays(90); $t->lt($fin); $t->addHour()) {
            $total = 0.06 * $this->bruit(0.2); // veilles (kWh sur l'heure)
            foreach ($equipements as $type => $eq) {
                $kwh = $eq->puissance_nominale / 1000 * $this->duty($type, (int) $t->format('G'), $t->dayOfWeekIso) * $this->bruit();
                $total += $kwh;
                if ($capteur = $capteursSct->get($eq->id)) {
                    $lignes[] = $this->ligne($capteur, $c, $eq->id, $t, $kwh);
                }
            }
            $lignes[] = $this->ligne($pzem, $c, null, $t, $total);
        }
        foreach (array_chunk($lignes, 1000) as $lot) {
            ConsommationHoraire::upsert($lot, ['capteur_id', 'heure'], ['energie_kwh', 'puissance_moy', 'puissance_max', 'tension_moy', 'courant_moy', 'frequence_moy', 'nb_mesures']);
        }
    }

    private function historiqueGlobal(Capteur $pzem, float $moyenneKw): void
    {
        $lignes = [];
        $fin = now()->startOfHour();
        for ($t = $fin->copy()->subDays(60); $t->lt($fin); $t->addHour()) {
            $h = (int) $t->format('G');
            $profil = ($h >= 18 && $h <= 23) ? 1.4 : (($h >= 0 && $h <= 5) ? 0.6 : 1.0);
            $lignes[] = $this->ligne($pzem, $pzem->compteur, null, $t, max(0.05, $moyenneKw / 3 * $profil * $this->bruit(0.25)));
        }
        foreach (array_chunk($lignes, 1000) as $lot) {
            ConsommationHoraire::upsert($lot, ['capteur_id', 'heure'], ['energie_kwh', 'puissance_moy', 'puissance_max', 'tension_moy', 'courant_moy', 'frequence_moy', 'nb_mesures']);
        }
    }

    private function ligne(Capteur $capteur, Compteur $c, ?int $equipementId, Carbon $heure, float $kwh): array
    {
        $watts = $kwh * 1000;
        $tension = 229.5 + mt_rand(-30, 30) / 10;

        return [
            'capteur_id' => $capteur->id, 'compteur_id' => $c->id, 'equipement_id' => $equipementId,
            'heure' => $heure->toDateTimeString(),
            'energie_kwh' => round($kwh, 5), 'puissance_moy' => round($watts, 1), 'puissance_max' => round($watts * 1.3, 1),
            'tension_moy' => round($tension, 1), 'courant_moy' => round($watts / ($tension * 0.95), 2),
            'frequence_moy' => round(50 + mt_rand(-10, 10) / 100, 2), 'nb_mesures' => 720,
        ];
    }

    /** 3 dernières heures de mesures brutes, toutes les 5 minutes (historique de la page Consommation). */
    private function mesuresRecentes(Compteur $c, Capteur $pzem): void
    {
        $energie = 1500.0;
        $rows = [];
        for ($t = now()->subHours(3)->startOfMinute(); $t->lt(now()); $t->addMinutes(5)) {
            $p = 600 + (int) $t->format('G') % 5 * 150 + mt_rand(-80, 80);
            $energie += $p * 300 / 3_600_000;
            $rows[] = [
                'capteur_id' => $pzem->id, 'compteur_id' => $c->id, 'equipement_id' => null,
                'date_heure' => $t->toDateTimeString(), 'tension' => 229 + mt_rand(-20, 20) / 10,
                'courant' => round($p / 230, 2), 'puissance' => $p, 'energie' => round($energie, 5),
                'frequence' => 50 + mt_rand(-10, 10) / 100, 'facteur_puissance' => 0.97,
            ];
        }
        Mesure::insert($rows);
    }

    private function alertes(Compteur $c, Capteur $pzem): void
    {
        $definitions = [
            [TypeAlerte::PicConsommation, 'Le Four électrique a dépassé le seuil de 1800 W (mesuré : 2040 W).', 1800, 2040, now()->subHours(3), Alerte::NON_LUE],
            [TypeAlerte::TensionHaute, 'Tension mesurée à 245 V sur le circuit cuisine (seuil : 240 V).', 240, 245, now()->subDay()->setTime(21, 40), Alerte::NON_LUE],
            [TypeAlerte::CapteurHorsLigne, 'Le capteur esp32-awa-sct4 ne répond plus depuis 2 heures.', 60, null, now()->subDays(2)->setTime(18, 5), Alerte::LUE],
            [TypeAlerte::ConsommationJournaliere, "Consommation journalière proche de l'objectif mensuel (300 kWh).", 10, 9.6, now()->subDays(3)->setTime(9, 0), Alerte::LUE],
            [TypeAlerte::PicConsommation, 'Le Climatiseur a dépassé le seuil de 1400 W (mesuré : 1512 W).', 1400, 1512, now()->subDays(4)->setTime(15, 30), Alerte::LUE],
        ];
        foreach ($definitions as [$type, $message, $seuil, $valeur, $date, $statut]) {
            Alerte::firstOrCreate(['compteur_id' => $c->id, 'type' => $type->value, 'date_heure' => $date], [
                'capteur_id' => $pzem->id, 'niveau' => $type->niveau(), 'message' => $message,
                'seuil' => $seuil, 'valeur' => $valeur, 'statut' => $statut, 'lue_at' => $statut === Alerte::LUE ? $date : null,
            ]);
        }
    }

    private function paiements(Compteur $c): void
    {
        $abonne = $c->abonne;
        MoyenDePaiement::firstOrCreate(['abonne_id' => $abonne->id, 'type' => MoyenPaiement::OrangeMoney], [
            'libelle' => 'Orange Money', 'telephone' => '+221770001234', 'par_defaut' => true,
        ]);
        MoyenDePaiement::firstOrCreate(['abonne_id' => $abonne->id, 'type' => MoyenPaiement::Carte], [
            'libelle' => 'Visa', 'derniers_chiffres' => '4242', 'expiration' => '08/28',
        ]);

        // [jours écoulés, montant, moyen, statut]
        foreach ([
            [0, 5000, MoyenPaiement::OrangeMoney, StatutPaiement::Reussi],
            [2, 2000, MoyenPaiement::Wave, StatutPaiement::Reussi],
            [10, 10000, MoyenPaiement::Carte, StatutPaiement::Reussi],
            [18, 1000, MoyenPaiement::OrangeMoney, StatutPaiement::Echoue],
            [25, 5000, MoyenPaiement::Wave, StatutPaiement::Reussi],
        ] as $n => [$jours, $montant, $moyen, $statut]) {
            $date = now()->subDays($jours)->setTime(9 + $n, 12);
            $ok = $statut === StatutPaiement::Reussi;
            Paiement::firstOrCreate(['reference' => 'SC-DEMO'.str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT)], [
                'abonne_id' => $abonne->id, 'compteur_id' => $c->id, 'type' => 'recharge',
                'montant_fcfa' => $montant, 'frais_fcfa' => 0, 'kwh_estimes' => round($montant / 82, 2),
                'moyen' => $moyen, 'telephone' => $moyen->requiertTelephone() ? '+221770001234' : null,
                'statut' => $statut, 'paye_le' => $ok ? $date : null,
                'code_recharge' => $ok ? '1234 5678 9012 3456 78'.($n + 1).'0' : null,
                'message_erreur' => $ok ? null : 'Solde insuffisant',
                'created_at' => $date, 'updated_at' => $date,
            ]);
        }
    }
}
