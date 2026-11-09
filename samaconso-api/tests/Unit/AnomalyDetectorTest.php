<?php

namespace Tests\Unit;

use App\Services\AnomalyDetector;
use PHPUnit\Framework\TestCase;

class AnomalyDetectorTest extends TestCase
{
    public function test_pic_bien_au_dessus_des_habitudes(): void
    {
        $this->assertTrue(AnomalyDetector::estAnormale(2000, 500, 80, 10));
    }

    public function test_valeur_habituelle(): void
    {
        $this->assertFalse(AnomalyDetector::estAnormale(560, 500, 80, 10));
    }

    public function test_historique_insuffisant_ne_declenche_pas(): void
    {
        $this->assertFalse(AnomalyDetector::estAnormale(2000, 500, 80, 3));
    }

    public function test_petite_puissance_ignoree(): void
    {
        $this->assertFalse(AnomalyDetector::estAnormale(150, 5, 1, 20));
    }

    public function test_signal_tres_stable_utilise_un_plancher_de_marge(): void
    {
        // écart-type quasi nul : sans plancher, 330 W serait « anormal » par rapport à 300 W
        $this->assertFalse(AnomalyDetector::estAnormale(330, 300, 0.0, 10));
        $this->assertTrue(AnomalyDetector::estAnormale(700, 300, 0.0, 10));
    }
}
