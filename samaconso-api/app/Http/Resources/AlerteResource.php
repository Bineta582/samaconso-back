<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlerteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'compteur_id' => $this->compteur_id,
            'type' => $this->type->value,
            'type_libelle' => $this->type->label(),
            'niveau' => $this->niveau,
            'message' => $this->message,
            'seuil' => $this->seuil,
            'unite' => $this->type->unite(),
            'valeur' => $this->valeur,
            'date_heure' => $this->date_heure->toIso8601String(),
            'statut' => $this->statut,
            'lue_at' => $this->lue_at?->toIso8601String(),
            'compteur' => $this->whenLoaded('compteur', fn () => [
                'id' => $this->compteur->id,
                'numero_compteur' => $this->compteur->numero_compteur,
            ]),
            'capteur' => $this->whenLoaded('capteur', fn () => $this->capteur ? [
                'id' => $this->capteur->id, 'device_id' => $this->capteur->device_id,
            ] : null),
        ];
    }
}
