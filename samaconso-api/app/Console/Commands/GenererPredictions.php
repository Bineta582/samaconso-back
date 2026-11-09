<?php

namespace App\Console\Commands;

use App\Models\Compteur;
use App\Models\ConsommationHoraire;
use App\Services\PredictionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenererPredictions extends Command
{
    protected $signature = 'predictions:generer {--compteur= : Limiter à un compteur}';

    protected $description = 'Génère les prédictions (7 et 30 jours) et les recommandations de chaque compteur actif';

    public function handle(PredictionService $service): int
    {
        $n = 0;
        Compteur::query()->where('statut', 'actif')
            ->when($this->option('compteur'), fn ($q, $id) => $q->whereKey($id))
            ->with('abonne')
            ->each(function (Compteur $c) use ($service, &$n) {
                // Inutile de prédire un compteur sans aucune donnée récente.
                $actif = ConsommationHoraire::query()->where('compteur_id', $c->id)
                    ->where('heure', '>=', now()->subDays(30))->exists();
                if (! $actif) {
                    return;
                }
                try {
                    $service->generer($c);
                    $n++;
                } catch (Throwable $e) {
                    Log::error('Prédiction en échec', ['compteur' => $c->id, 'erreur' => $e->getMessage()]);
                    $this->warn("Compteur {$c->numero_compteur} : {$e->getMessage()}");
                }
            });

        $this->info("{$n} compteur(s) analysé(s).");

        return self::SUCCESS;
    }
}
