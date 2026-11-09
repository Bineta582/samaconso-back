<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Classe « Mesure » : date/heure, tension, courant, puissance, énergie, fréquence. */
class Mesure extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'mesures';

    protected $fillable = [
        'capteur_id', 'compteur_id', 'equipement_id', 'date_heure', 'tension', 'courant',
        'puissance', 'energie', 'frequence', 'facteur_puissance',
    ];

    protected function casts(): array
    {
        return [
            'date_heure' => 'datetime',
            'tension' => 'float', 'courant' => 'float', 'puissance' => 'float',
            'energie' => 'float', 'frequence' => 'float', 'facteur_puissance' => 'float',
        ];
    }

    public function capteur(): BelongsTo
    {
        return $this->belongsTo(Capteur::class);
    }

    public function compteur(): BelongsTo
    {
        return $this->belongsTo(Compteur::class);
    }

    public function alertes(): HasMany
    {
        return $this->hasMany(Alerte::class);
    }
}
