<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgerMesures extends Command
{
    protected $signature = 'mesures:purger {--jours= : Rétention en jours (défaut : config senelec.retention_mesures_jours)}';

    protected $description = 'Supprime les mesures brutes anciennes (les agrégats horaires sont conservés)';

    public function handle(): int
    {
        $jours = (int) ($this->option('jours') ?: config('senelec.retention_mesures_jours'));
        $limite = now()->subDays($jours);
        $total = 0;

        // Suppression par lots pour ne pas verrouiller la table (PostgreSQL ne supporte pas DELETE ... LIMIT).
        do {
            $n = DB::affectingStatement(
                'DELETE FROM mesures WHERE id IN (SELECT id FROM mesures WHERE date_heure < ? ORDER BY id LIMIT 20000)',
                [$limite]
            );
            $total += $n;
        } while ($n > 0);

        $this->info("{$total} mesure(s) brute(s) antérieure(s) au {$limite->toDateString()} supprimée(s).");

        return self::SUCCESS;
    }
}
