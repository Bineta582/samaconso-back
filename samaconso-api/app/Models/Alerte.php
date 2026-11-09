<?php

namespace App\Models;

use App\Enums\TypeAlerte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Classe « Alerte » : type, message, seuil, date/heure, statut. */
class Alerte extends Model
{
    public const NON_LUE = 'non_lue';
    public const LUE = 'lue';

    protected $table = 'alertes';

    protected $fillable = [
        'compteur_id', 'capteur_id', 'mesure_id', 'type', 'niveau', 'message',
        'seuil', 'valeur', 'date_heure', 'statut', 'lue_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeAlerte::class,
            'date_heure' => 'datetime',
            'lue_at' => 'datetime',
            'seuil' => 'float',
            'valeur' => 'float',
        ];
    }

    public function compteur(): BelongsTo
    {
        return $this->belongsTo(Compteur::class);
    }

    public function capteur(): BelongsTo
    {
        return $this->belongsTo(Capteur::class);
    }

    public function mesure(): BelongsTo
    {
        return $this->belongsTo(Mesure::class);
    }

    public function marquerCommeLue(): void
    {
        if ($this->statut !== self::LUE) {
            $this->update(['statut' => self::LUE, 'lue_at' => now()]);
        }
    }

    public function scopeNonLues(Builder $query): Builder
    {
        return $query->where('statut', self::NON_LUE);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('compteur_id', Compteur::query()->visibleTo($user)->select('id'));
    }
}
