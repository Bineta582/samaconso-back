<?php

namespace App\Console\Commands;

use App\Services\AgregationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AgregerMesures extends Command
{
    protected $signature = 'mesures:agreger {--heures=2 : Nombre d\'heures récentes à (re)calculer} {--depuis= : Date de début (rattrapage historique)}';

    protected $description = 'Agrège les mesures brutes en consommations horaires';

    public function handle(AgregationService $service): int
    {
        $from = $this->option('depuis')
            ? Carbon::parse($this->option('depuis'))->startOfHour()
            : now()->subHours((int) $this->option('heures'))->startOfHour();
        $to = now()->startOfHour()->addHour();

        $n = $service->agreger($from, $to);
        $this->info("{$n} agrégat(s) horaire(s) calculé(s) du {$from} au {$to}.");

        return self::SUCCESS;
    }
}
