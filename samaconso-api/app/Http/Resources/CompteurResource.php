<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompteurResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'abonne_id' => $this->abonne_id,
            'numero_compteur' => $this->numero_compteur,
            'type_compteur' => $this->type_compteur,
            'adresse' => $this->adresse,
            'statut' => $this->statut,
            'date_installation' => $this->date_installation?->toDateString(),
            'objectif_mensuel_kwh' => $this->objectifMensuelKwh(),
            'categorie_tarifaire' => $this->whenLoaded('abonne', fn () => $this->categorieTarifaire()),
            'capteurs_count' => $this->whenCounted('capteurs'),
            'equipements_count' => $this->whenCounted('equipements'),
        ];
    }
}
