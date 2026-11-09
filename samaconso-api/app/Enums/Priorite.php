<?php

namespace App\Enums;

enum Priorite: string
{
    case Haute = 'haute';
    case Moyenne = 'moyenne';
    case Basse = 'basse';

    /** Priorité déduite de l'impact financier mensuel estimé (FCFA). */
    public static function depuisImpact(float $fcfaParMois): self
    {
        return match (true) {
            $fcfaParMois >= 5000 => self::Haute,
            $fcfaParMois >= 2000 => self::Moyenne,
            default => self::Basse,
        };
    }
}
