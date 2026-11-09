<?php

namespace App\Support;

use RuntimeException;

/** Mesure rejetée (format ou valeurs hors plage physique). */
class MesureInvalide extends RuntimeException
{
}
