<?php

namespace App\Models;

use App\Enums\TypeAbonnement;
use App\Enums\TypeCapteur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Classe « Compteur » : numéro, type, adresse, statut, date d'installation. */
class Compteur extends Model
{
    protected $table = 'compteurs';

    protected $fillable = [
        'abonne_id', 'numero_compteur', 'type_compteur', 'adresse', 'statut',
        'date_installation', 'solde_initial_fcfa', 'solde_initial_at', 'objectif_mensuel_kwh',
    ];

    protected function casts(): array
    {
        return [
            'date_installation' => 'date',
            'solde_initial_fcfa' => 'float',
            'solde_initial_at' => 'datetime',
            'objectif_mensuel_kwh' => 'float',
        ];
    }

    public function abonne(): BelongsTo
    {
        return $this->belongsTo(Abonne::class);
    }

    public function capteurs(): HasMany
    {
        return $this->hasMany(Capteur::class);
    }

    public function equipements(): HasMany
    {
        return $this->hasMany(Equipement::class);
    }

    public function mesures(): HasMany
    {
        return $this->hasMany(Mesure::class);
    }

    public function alertes(): HasMany
    {
        return $this->hasMany(Alerte::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function recommandations(): HasMany
    {
        return $this->hasMany(Recommandation::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    /** Capteurs de mesure globale (PZEM-004T) : base de la consommation totale. */
    public function capteursGlobaux(): HasMany
    {
        return $this->capteurs()->where('type_capteur', TypeCapteur::Pzem->value);
    }

    /** Ids de capteurs servant au total : PZEM si présent, sinon tous les capteurs de mesure. */
    public function idsCapteursTotal(): array
    {
        $ids = $this->capteursGlobaux()->pluck('id')->all();

        return $ids ?: $this->capteurs()
            ->whereIn('type_capteur', [TypeCapteur::Pzem->value, TypeCapteur::Sct->value])
            ->pluck('id')->all();
    }

    public function typeAbonnement(): TypeAbonnement
    {
        return $this->abonne->type_abonnement;
    }

    public function categorieTarifaire(): string
    {
        return $this->typeAbonnement()->categorieTarifaire();
    }

    public function objectifMensuelKwh(): float
    {
        return $this->objectif_mensuel_kwh ?? (float) config('senelec.objectif_mensuel_kwh_defaut');
    }

    /** Restreint aux compteurs auxquels l'utilisateur a accès (admin : tous). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->estAdmin()) {
            return $query;
        }

        return $query->whereIn('abonne_id', Abonne::query()->select('id')->where('user_id', $user->id));
    }
}
