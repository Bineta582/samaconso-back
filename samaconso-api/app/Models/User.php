<?php

namespace App\Models;

use App\Enums\Profil;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Classe « Utilisateur » : nom, prénom, email, téléphone, mot de passe (bcrypt), profil.
 */
class User extends Authenticatable implements JWTSubject
{
    use Notifiable, SoftDeletes;

    protected $fillable = [
        'nom', 'prenom', 'email', 'telephone', 'password', 'profil', 'statut',
        'preferences_notifications', 'last_login_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'profil' => Profil::class,
            'password' => 'hashed',   // bcrypt (driver de hachage par défaut)
            'preferences_notifications' => 'array',
            'last_login_at' => 'datetime',
        ];
    }

    public function abonne(): HasOne
    {
        return $this->hasOne(Abonne::class);
    }

    public function administrateur(): HasOne
    {
        return $this->hasOne(Administrateur::class);
    }

    public function estAdmin(): bool
    {
        return $this->profil === Profil::Administrateur;
    }

    public function estActif(): bool
    {
        return $this->statut === 'actif';
    }

    public function nomComplet(): string
    {
        return trim($this->prenom.' '.$this->nom);
    }

    /** Préférences par défaut (page Paramètres → Notifications). */
    public function preferences(): array
    {
        return array_merge([
            'email' => true,
            'sms' => true,
            'rapport_hebdomadaire' => false,
            'recommandations' => true,
        ], $this->preferences_notifications ?? []);
    }

    // ---- JWTSubject -------------------------------------------------------
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return ['profil' => $this->profil?->value];
    }

    /** Numéro utilisé par le canal SMS. */
    public function routeNotificationForSms(): ?string
    {
        return $this->telephone;
    }
}
