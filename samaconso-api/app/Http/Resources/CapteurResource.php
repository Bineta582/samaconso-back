<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapteurResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'compteur_id' => $this->compteur_id,
            'equipement_id' => $this->equipement_id,
            'device_id' => $this->device_id,
            'type_capteur' => $this->type_capteur,
            'emplacement' => $this->emplacement,
            'statut' => $this->statut,
            'en_ligne' => $this->estEnLigne(),
            'date_installation' => $this->date_installation?->toDateString(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
