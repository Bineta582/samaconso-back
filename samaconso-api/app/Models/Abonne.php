<?php

namespace App\Models;

use App\Enums\TypeAbonnement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Classe « Abonne » : idAbonne, typeAbonnement. */
class Abonne extends Model
{
    protected $table = 'abonnes';

    protected $fillable = ['user_id', 'type_abonnement', 'numero_contrat'];

    protected function casts(): array
    {
        return ['type_abonnement' => TypeAbonnement::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function compteurs(): HasMany
    {
        return $this->hasMany(Compteur::class);
    }

    public function moyensPaiement(): HasMany
    {
        return $this->hasMany(MoyenDePaiement::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    public function seuils(): HasMany
    {
        return $this->hasMany(SeuilAlerte::class);
    }
}
