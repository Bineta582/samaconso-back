<?php

namespace Tests\Feature\Concerns;

use App\Enums\TypeAbonnement;
use App\Models\Abonne;
use App\Models\Capteur;
use App\Models\Compteur;
use App\Models\User;

trait CreeDonnees
{
    /** @return array{0: User, 1: Abonne, 2: Compteur} */
    protected function creerAbonne(string $numero = 'SN-0000001', string $type = 'menage'): array
    {
        $user = User::factory()->create();
        $abonne = Abonne::create(['user_id' => $user->id, 'type_abonnement' => TypeAbonnement::from($type)]);
        $compteur = Compteur::create([
            'abonne_id' => $abonne->id, 'numero_compteur' => $numero, 'type_compteur' => 'woyofal',
            'statut' => 'actif', 'solde_initial_at' => now()->subDay(),
        ]);

        return [$user, $abonne, $compteur];
    }

    /** @return array{0: Capteur, 1: string} capteur et clé API en clair */
    protected function creerCapteur(Compteur $compteur, string $deviceId = 'esp32-test-pzem', string $type = 'PZEM-004T'): array
    {
        $cle = 'cle-secrete-'.$deviceId;
        $capteur = Capteur::create([
            'compteur_id' => $compteur->id, 'device_id' => $deviceId, 'type_capteur' => $type,
            'statut' => 'actif', 'api_key_hash' => Capteur::hacherCle($cle),
        ]);

        return [$capteur, $cle];
    }
}
