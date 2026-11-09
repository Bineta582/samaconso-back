<?php

namespace App\Models;

use App\Enums\TypeAlerte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeuilAlerte extends Model
{
    protected $table = 'seuils_alerte';

    protected $fillable = ['abonne_id', 'equipement_id', 'type', 'valeur', 'actif'];

    protected function casts(): array
    {
        return ['type' => TypeAlerte::class, 'valeur' => 'float', 'actif' => 'boolean'];
    }

    public function abonne(): BelongsTo
    {
        return $this->belongsTo(Abonne::class);
    }

    public function equipement(): BelongsTo
    {
        return $this->belongsTo(Equipement::class);
    }
}
