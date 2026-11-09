<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Classe « Equipement » : nom, type, emplacement, puissance nominale, description. */
class Equipement extends Model
{
    protected $table = 'equipements';

    protected $fillable = [
        'compteur_id', 'nom', 'type', 'emplacement', 'puissance_nominale', 'description', 'coupure_auto_w',
        'relais_capteur_id',
    ];

    protected function casts(): array
    {
        return ['puissance_nominale' => 'float', 'coupure_auto_w' => 'float'];
    }

    public function compteur(): BelongsTo
    {
        return $this->belongsTo(Compteur::class);
    }

    public function capteur(): HasOne
    {
        return $this->hasOne(Capteur::class);
    }

    /** Module relais (capteur RELAIS) qui commande cet équipement. */
    public function relais(): BelongsTo
    {
        return $this->belongsTo(Capteur::class, 'relais_capteur_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('compteur_id', Compteur::query()->visibleTo($user)->select('id'));
    }
}
