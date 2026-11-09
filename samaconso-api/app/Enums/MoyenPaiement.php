<?php

namespace App\Enums;

enum MoyenPaiement: string
{
    case OrangeMoney = 'orange_money';
    case Wave = 'wave';
    case FreeMoney = 'free_money';
    case Carte = 'carte';

    public function label(): string
    {
        return match ($this) {
            self::OrangeMoney => 'Orange Money',
            self::Wave => 'Wave',
            self::FreeMoney => 'Free Money',
            self::Carte => 'Carte bancaire',
        };
    }

    /** Les moyens « mobile money » exigent un numéro de téléphone. */
    public function requiertTelephone(): bool
    {
        return $this !== self::Carte;
    }
}
