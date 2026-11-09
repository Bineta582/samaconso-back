<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Classe « Prediction » : période, date, consommation prévue, coût prévu, modèle, précision. */
class Prediction extends Model
{
    protected $table = 'predictions';

    protected $fillable = [
        'compteur_id', 'periode', 'date_prediction', 'date_debut', 'date_fin',
        'consommation_prevue', 'cout_prevu', 'modele', 'precision', 'details',
    ];

    protected function casts(): array
    {
        return [
            'date_prediction' => 'datetime',
            'date_debut' => 'date',
            'date_fin' => 'date',
            'consommation_prevue' => 'float',
            'cout_prevu' => 'float',
            'precision' => 'float',
            'details' => 'array',
        ];
    }

    public function compteur(): BelongsTo
    {
        return $this->belongsTo(Compteur::class);
    }

    public function recommandations(): HasMany
    {
        return $this->hasMany(Recommandation::class);
    }
}
