<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EquipementResource extends JsonResource
{
    /** @var array<int, float> puissance instantanée (W) par id d'équipement, fournie par le contrôleur */
    public static array $instantane = [];

    public function toArray(Request $request): array
    {
        $capteur = $this->relationLoaded('capteur') ? $this->capteur : null;
        $watts = self::$instantane[$this->id] ?? null;

        return [
            'id' => $this->id,
            'compteur_id' => $this->compteur_id,
            'nom' => $this->nom,
            'type' => $this->type,
            'emplacement' => $this->emplacement,
            'puissance_nominale_w' => $this->puissance_nominale,
            'description' => $this->description,
            'coupure_auto_w' => $this->coupure_auto_w,
            'relais_capteur_id' => $this->relais_capteur_id,
            'capteur' => $capteur ? new CapteurResource($capteur) : null,
            'capteur_associe' => $capteur !== null,
            // état déduit de la dernière mesure : null si aucun capteur ne le suit
            'etat' => $capteur ? ($watts !== null && $watts > 10 ? 'en_marche' : 'eteint') : null,
            'puissance_instantanee_w' => $capteur ? ($watts ?? 0.0) : null,
        ];
    }
}
