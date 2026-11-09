<?php

namespace App\Enums;

enum TypeCapteur: string
{
    case Pzem = 'PZEM-004T';   // mesure globale (tension, courant, puissance, énergie, fréquence, cos φ)
    case Sct = 'SCT-013';      // courant d'un circuit / équipement
    case Relais = 'RELAIS';    // module relais (coupure automatique)
    case Esp32 = 'ESP32';      // microcontrôleur seul
}
