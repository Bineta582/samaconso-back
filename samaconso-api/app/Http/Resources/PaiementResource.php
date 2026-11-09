<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaiementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'compteur_id' => $this->compteur_id,
            'type' => $this->type,
            'montant_fcfa' => $this->montant_fcfa,
            'frais_fcfa' => $this->frais_fcfa,
            'total_fcfa' => $this->total(),
            'kwh_estimes' => $this->kwh_estimes,
            'moyen' => $this->moyen->value,
            'moyen_libelle' => $this->moyen->label(),
            'statut' => $this->statut->value,
            'code_recharge' => $this->code_recharge,
            'message_erreur' => $this->message_erreur,
            'date' => ($this->paye_le ?? $this->created_at)->toIso8601String(),
        ];
    }
}
