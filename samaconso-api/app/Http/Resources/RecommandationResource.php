<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecommandationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'compteur_id' => $this->compteur_id,
            'titre' => $this->titre,
            'contenu' => $this->contenu,
            'priorite' => $this->priorite->value,
            'impact_estime_fcfa' => $this->impact_estime_fcfa,
            'date_generation' => $this->date_generation->toIso8601String(),
        ];
    }
}
