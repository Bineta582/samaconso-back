<?php

namespace Tests\Unit;

use App\Services\TariffService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Grille CRSE du 1er janvier 2026 (prépaiement) — valeurs de référence calculées à la main. */
class TariffServiceTest extends TestCase
{
    private TariffService $tarif;

    protected function setUp(): void
    {
        $this->tarif = new TariffService(['tarifs' => [
            'DPP' => [['jusqua' => 150, 'prix' => 82.00], ['jusqua' => 250, 'prix' => 136.49], ['jusqua' => null, 'prix' => 136.49]],
            'PPP' => [['jusqua' => 50, 'prix' => 147.43], ['jusqua' => 500, 'prix' => 189.84], ['jusqua' => null, 'prix' => 189.84]],
        ]]);
    }

    public function test_cout_progressif_par_tranche(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->tarif->cout(0, 'DPP'), 0.001);
        $this->assertEqualsWithDelta(12300.00, $this->tarif->cout(150, 'DPP'), 0.001);
        $this->assertEqualsWithDelta(25949.00, $this->tarif->cout(250, 'DPP'), 0.001);
        // 3e tranche valorisée au tarif de la 2e (règle du prépaiement)
        $this->assertEqualsWithDelta(32773.50, $this->tarif->cout(300, 'DPP'), 0.001);
        $this->assertEqualsWithDelta(7371.50 + 50 * 189.84, $this->tarif->cout(100, 'PPP'), 0.001);
    }

    public function test_cout_marginal_depend_de_la_position_dans_le_mois(): void
    {
        // 140 kWh déjà consommés : 10 kWh à 82 F puis 10 kWh à 136,49 F
        $this->assertEqualsWithDelta(2184.90, $this->tarif->coutMarginal(140, 20, 'DPP'), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->tarif->coutMarginal(100, 0, 'DPP'), 0.001);
    }

    public function test_kwh_pour_un_montant(): void
    {
        $this->assertEqualsWithDelta(5000 / 82, $this->tarif->kwhPourMontant(5000, 0, 'DPP'), 0.001);
        $this->assertEqualsWithDelta(150.0, $this->tarif->kwhPourMontant(12300, 0, 'DPP'), 0.001);
        $this->assertEqualsWithDelta(2 + (5000 - 164) / 136.49, $this->tarif->kwhPourMontant(5000, 148, 'DPP'), 0.001);
    }

    public function test_aller_retour_montant_energie(): void
    {
        foreach ([500, 2000, 5000, 20000, 60000] as $montant) {
            $kwh = $this->tarif->kwhPourMontant($montant, 0, 'DPP');
            $this->assertEqualsWithDelta($montant, $this->tarif->cout($kwh, 'DPP'), 0.5);
        }
    }

    public function test_categorie_inconnue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->tarif->cout(1, 'XXX');
    }
}
