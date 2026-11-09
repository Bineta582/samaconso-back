<?php

namespace App\Models;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Transaction de facturation (recharge Woyofal). */
class Paiement extends Model
{
    protected $table = 'paiements';

    protected $fillable = [
        'reference', 'abonne_id', 'compteur_id', 'moyen_paiement_id', 'type', 'montant_fcfa',
        'frais_fcfa', 'kwh_estimes', 'moyen', 'telephone', 'statut', 'reference_prestataire',
        'code_recharge', 'message_erreur', 'paye_le', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'moyen' => MoyenPaiement::class,
            'statut' => StatutPaiement::class,
            'montant_fcfa' => 'float',
            'frais_fcfa' => 'float',
            'kwh_estimes' => 'float',
            'paye_le' => 'datetime',
        ];
    }

    public function abonne(): BelongsTo
    {
        return $this->belongsTo(Abonne::class);
    }

    public function compteur(): BelongsTo
    {
        return $this->belongsTo(Compteur::class);
    }

    public function total(): float
    {
        return $this->montant_fcfa + $this->frais_fcfa;
    }
}
