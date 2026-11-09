<?php

namespace App\Console\Commands;

use App\Models\Compteur;
use App\Services\AlertEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class VerifierAlertes extends Command
{
    protected $signature = 'alertes:verifier';

    protected $description = 'Capteurs hors ligne, seuils journaliers (kWh / FCFA) et crédit Woyofal bas';

    public function handle(AlertEngine $moteur): int
    {
        $horsLigne = $moteur->evaluerCapteursHorsLigne();

        $n = 0;
        Compteur::query()->where('statut', 'actif')->with('abonne.user')->each(function (Compteur $c) use ($moteur, &$n) {
            try {
                $moteur->evaluerCompteur($c);
                $n++;
            } catch (Throwable $e) {
                Log::error('Vérification des alertes en échec', ['compteur' => $c->id, 'erreur' => $e->getMessage()]);
            }
        });

        $this->info("{$horsLigne} alerte(s) capteur hors ligne ; {$n} compteur(s) vérifié(s).");

        return self::SUCCESS;
    }
}
