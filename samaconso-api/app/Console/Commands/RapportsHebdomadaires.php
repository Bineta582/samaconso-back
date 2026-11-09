<?php

namespace App\Console\Commands;

use App\Enums\Profil;
use App\Models\Recommandation;
use App\Models\User;
use App\Notifications\RapportHebdomadaire;
use App\Services\ConsommationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RapportsHebdomadaires extends Command
{
    protected $signature = 'rapports:hebdomadaires';

    protected $description = 'Envoie le bilan de la semaine aux abonnés qui ont activé le rapport hebdomadaire';

    public function handle(ConsommationService $conso): int
    {
        $debut = now()->subWeek()->startOfWeek();
        $fin = $debut->copy()->addWeek();
        $precedent = $debut->copy()->subWeek();
        $n = 0;

        User::query()->where('profil', Profil::Abonne->value)->where('statut', 'actif')
            ->with('abonne.compteurs.abonne')
            ->each(function (User $u) use ($conso, $debut, $fin, $precedent, &$n) {
                if (! ($u->preferences()['rapport_hebdomadaire'] ?? false)) {
                    return;
                }
                foreach ($u->abonne?->compteurs ?? [] as $c) {
                    try {
                        $kwh = $conso->energie($c, $debut, $fin);
                        $avant = $conso->energie($c, $precedent, $debut);
                        $top = collect($conso->repartition($c, $debut, $fin))->firstWhere(fn ($l) => $l['equipement_id'] !== null);

                        $u->notify(new RapportHebdomadaire($c->numero_compteur, [
                            'kwh' => $kwh,
                            'cout' => $conso->cout($c, $debut, $fin),
                            'variation_pct' => $avant > 0 ? ($kwh - $avant) / $avant * 100 : null,
                            'top' => $top['nom'] ?? null,
                            'conseil' => Recommandation::query()->where('compteur_id', $c->id)->orderByDesc('impact_estime_fcfa')->value('titre'),
                        ]));
                        $n++;
                    } catch (Throwable $e) {
                        Log::error('Rapport hebdomadaire en échec', ['compteur' => $c->id, 'erreur' => $e->getMessage()]);
                    }
                }
            });

        $this->info("{$n} rapport(s) envoyé(s).");

        return self::SUCCESS;
    }
}
