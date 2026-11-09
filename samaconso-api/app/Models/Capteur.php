<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Classe « Capteur » : deviceId, type, emplacement, statut, date d'installation. */
class Capteur extends Model
{
    protected $table = 'capteurs';

    protected $fillable = [
        'compteur_id', 'equipement_id', 'device_id', 'type_capteur', 'emplacement',
        'statut', 'date_installation', 'last_seen_at', 'api_key_hash',
    ];

    protected $hidden = ['api_key_hash'];

    protected function casts(): array
    {
        return ['date_installation' => 'date', 'last_seen_at' => 'datetime'];
    }

    public function compteur(): BelongsTo
    {
        return $this->belongsTo(Compteur::class);
    }

    public function equipement(): BelongsTo
    {
        return $this->belongsTo(Equipement::class);
    }

    public function mesures(): HasMany
    {
        return $this->hasMany(Mesure::class);
    }

    /** Connecté = mesure reçue dans la fenêtre de tolérance. */
    public function estEnLigne(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes((int) config('senelec.capteur_hors_ligne_minutes')));
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('compteur_id', Compteur::query()->visibleTo($user)->select('id'));
    }

    public static function hacherCle(string $cle): string
    {
        return hash('sha256', $cle);
    }
}
