<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Calcul du coût de l'énergie selon la grille SENELEC (tranches progressives, cumulées par mois).
 * Classe volontairement sans dépendance au framework : testable seule.
 */
class TariffService
{
    /** @param array{tarifs: array<string, list<array{jusqua: int|null, prix: float}>>} $config */
    public function __construct(private readonly array $config)
    {
    }

    /** Coût (FCFA) de $kwh consommés depuis le début du mois. */
    public function cout(float $kwh, string $categorie): float
    {
        if ($kwh <= 0) {
            return 0.0;
        }

        $total = 0.0;
        $debut = 0.0;
        foreach ($this->tranches($categorie) as $tranche) {
            $fin = $tranche['jusqua'] ?? INF;
            if ($kwh <= $debut) {
                break;
            }
            $total += (min($kwh, $fin) - $debut) * $tranche['prix'];
            $debut = $fin;
        }

        return round($total, 2);
    }

    /**
     * Coût de $kwhAjoutes sachant que $kwhDejaConsommes l'ont déjà été ce mois-ci
     * (c'est la position dans les tranches qui détermine le prix).
     */
    public function coutMarginal(float $kwhDejaConsommes, float $kwhAjoutes, string $categorie): float
    {
        $avant = max(0.0, $kwhDejaConsommes);

        return round(
            $this->cout($avant + max(0.0, $kwhAjoutes), $categorie) - $this->cout($avant, $categorie),
            2
        );
    }

    /**
     * Inverse : combien de kWh obtient-on avec $fcfa quand $kwhDejaAchetes ont déjà été achetés ce mois-ci.
     */
    public function kwhPourMontant(float $fcfa, float $kwhDejaAchetes, string $categorie): float
    {
        if ($fcfa <= 0) {
            return 0.0;
        }

        $reste = $fcfa;
        $kwh = 0.0;
        $debut = 0.0;
        foreach ($this->tranches($categorie) as $tranche) {
            $fin = $tranche['jusqua'] ?? INF;
            $position = max($debut, $kwhDejaAchetes + $kwh);
            if ($position >= $fin) {
                $debut = $fin;
                continue;
            }
            $capaciteKwh = $fin - $position;
            $capaciteFcfa = is_infinite($capaciteKwh) ? INF : $capaciteKwh * $tranche['prix'];
            if ($reste <= $capaciteFcfa) {
                $kwh += $reste / $tranche['prix'];
                $reste = 0.0;
                break;
            }
            $kwh += $capaciteKwh;
            $reste -= $capaciteFcfa;
            $debut = $fin;
        }

        return round($kwh, 3);
    }

    /** Prix du kWh à la position donnée (kWh déjà consommés ce mois-ci). */
    public function prixMarginal(float $kwhDejaConsommes, string $categorie): float
    {
        foreach ($this->tranches($categorie) as $tranche) {
            if ($tranche['jusqua'] === null || $kwhDejaConsommes < $tranche['jusqua']) {
                return $tranche['prix'];
            }
        }

        return 0.0;
    }

    /** @return list<array{jusqua: int|null, prix: float}> */
    private function tranches(string $categorie): array
    {
        $tranches = $this->config['tarifs'][$categorie] ?? null;
        if ($tranches === null) {
            throw new InvalidArgumentException("Catégorie tarifaire inconnue : {$categorie}");
        }

        return $tranches;
    }
}
