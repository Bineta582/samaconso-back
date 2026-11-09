<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PredictionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'compteur_id' => $this->compteur_id,
            'periode' => $this->periode,
            'date_prediction' => $this->date_prediction->toIso8601String(),
            'date_debut' => $this->date_debut->toDateString(),
            'date_fin' => $this->date_fin->toDateString(),
            'consommation_prevue_kwh' => $this->consommation_prevue,
            'cout_prevu_fcfa' => $this->cout_prevu,
            'modele' => $this->modele,
            'precision' => $this->precision,
            'intervalle_kwh' => [
                'bas' => $this->details['low_kwh'] ?? null,
                'haut' => $this->details['high_kwh'] ?? null,
            ],
            'serie_journaliere' => $this->details['daily'] ?? [],
        ];
    }
}
