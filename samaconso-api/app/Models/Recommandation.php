<?php

namespace App\Models;

use App\Enums\Priorite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Classe « Recommandation » : titre, contenu, date de génération, priorité. */
class Recommandation extends Model
{
    protected $table = 'recommandations';

    protected $fillable = [
        'compteur_id', 'prediction_id', 'code', 'titre', 'contenu', 'priorite',
        'impact_estime_fcfa', 'date_generation',
    ];

    protected function casts(): array
    {
        return [
            'priorite' => Priorite::class,
            'impact_estime_fcfa' => 'float',
            'date_generation' => 'datetime',
        ];
    }

    public function compteur(): BelongsTo
    {
        return $this->belongsTo(Compteur::class);
    }

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }
}
