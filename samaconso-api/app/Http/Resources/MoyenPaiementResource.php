<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MoyenPaiementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'libelle' => $this->libelle ?? $this->type->label(),
            'telephone_masque' => $this->telephone ? '•••• '.substr($this->telephone, -4) : null,
            'derniers_chiffres' => $this->derniers_chiffres,
            'expiration' => $this->expiration,
            'par_defaut' => $this->par_defaut,
        ];
    }
}
