<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommandeAppareil extends Model
{
    public const EN_ATTENTE = 'en_attente';

    public const EXECUTEE = 'executee';

    public const ECHOUEE = 'echouee';

    protected $table = 'commandes_appareil';

    protected $fillable = [
        'capteur_id',
        'equipement_id',
        'action',
        'raison',
        'statut',
        'terminee_at',
        'erreur',
    ];

    protected function casts(): array
    {
        return ['terminee_at' => 'datetime'];
    }

    public function capteur(): BelongsTo
    {
        return $this->belongsTo(Capteur::class);
    }

    public function equipement(): BelongsTo
    {
        return $this->belongsTo(Equipement::class);
    }
}
