<?php

namespace App\Enums;

enum TypeAbonnement: string
{
    case Menage = 'menage';
    case PetiteEntreprise = 'petite_entreprise';

    /** Catégorie tarifaire SENELEC (clé de config/senelec.php). */
    public function categorieTarifaire(): string
    {
        return match ($this) {
            self::Menage => 'DPP',
            self::PetiteEntreprise => 'PPP',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Menage => 'Ménage',
            self::PetiteEntreprise => 'Petite entreprise',
        };
    }
}
