<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MesureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'capteur_id' => $this->capteur_id,
            'date_heure' => $this->date_heure->toIso8601String(),
            'tension' => $this->tension,
            'courant' => $this->courant,
            'puissance' => $this->puissance,
            'energie' => $this->energie,
            'frequence' => $this->frequence,
            'facteur_puissance' => $this->facteur_puissance,
        ];
    }
}
