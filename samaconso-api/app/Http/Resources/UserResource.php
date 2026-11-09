<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'profil' => $this->profil->value,
            'statut' => $this->statut,
            'abonne' => $this->whenLoaded('abonne', fn () => $this->abonne ? [
                'id' => $this->abonne->id,
                'type_abonnement' => $this->abonne->type_abonnement->value,
                'numero_contrat' => $this->abonne->numero_contrat,
            ] : null),
            'preferences_notifications' => $this->preferences(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
