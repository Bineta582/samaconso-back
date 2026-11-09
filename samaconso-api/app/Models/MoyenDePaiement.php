<?php

namespace App\Models;

use App\Enums\MoyenPaiement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoyenDePaiement extends Model
{
    protected $table = 'moyens_paiement';

    protected $fillable = [
        'abonne_id', 'type', 'libelle', 'telephone', 'derniers_chiffres',
        'expiration', 'token_prestataire', 'par_defaut',
    ];

    protected $hidden = ['token_prestataire'];

    protected function casts(): array
    {
        return ['type' => MoyenPaiement::class, 'par_defaut' => 'boolean'];
    }

    public function abonne(): BelongsTo
    {
        return $this->belongsTo(Abonne::class);
    }
}
