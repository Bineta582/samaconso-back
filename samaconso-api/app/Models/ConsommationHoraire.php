<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Agrégat horaire des mesures (une ligne par capteur et par heure). */
class ConsommationHoraire extends Model
{
    public $timestamps = false;

    protected $table = 'consommations_horaires';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['heure' => 'datetime', 'energie_kwh' => 'float'];
    }
}
