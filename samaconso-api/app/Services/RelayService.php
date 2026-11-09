<?php

namespace App\Services;

use App\Models\CommandeAppareil;
use App\Models\Equipement;

/** Enregistre les commandes de relais que les appareils récupèrent par polling HTTP. */
class RelayService
{
    public function commander(Equipement $equipement, string $action, string $raison = 'manuel'): bool
    {
        if (! in_array($action, ['on', 'off'], true)) {
            return false;
        }
        $relais = $equipement->relais;
        if (! $relais) {
            return false;
        }

        CommandeAppareil::query()->create([
            'capteur_id' => $relais->id,
            'equipement_id' => $equipement->id,
            'action' => $action,
            'raison' => $raison,
            'statut' => CommandeAppareil::EN_ATTENTE,
        ]);

        return true;
    }
}
