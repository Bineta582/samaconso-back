<?php

namespace Tests\Feature;

use App\Enums\TypeAbonnement;
use App\Models\Abonne;
use App\Models\CommandeAppareil;
use App\Models\Compteur;
use App\Models\Equipement;
use App\Models\Mesure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreeDonnees;
use Tests\TestCase;

class DeviceApiTest extends TestCase
{
    use CreeDonnees, RefreshDatabase;

    private function entetes(string $deviceId, string $cle): array
    {
        return ['X-Device-Id' => $deviceId, 'X-Device-Key' => $cle];
    }

    private function nouveauCompte(): array
    {
        $user = User::create([
            'nom' => 'Test',
            'prenom' => 'Appareil',
            'email' => uniqid('device-', true).'@example.test',
            'password' => 'Password123',
            'profil' => 'abonne',
            'statut' => 'actif',
        ]);
        $abonne = Abonne::create([
            'user_id' => $user->id,
            'type_abonnement' => TypeAbonnement::from('menage'),
        ]);
        $compteur = Compteur::create([
            'abonne_id' => $abonne->id,
            'numero_compteur' => uniqid('SN-'),
            'type_compteur' => 'woyofal',
            'statut' => 'actif',
            'solde_initial_at' => now()->subDay(),
        ]);

        return [$user, $compteur];
    }

    public function test_un_appareil_peut_publier_son_statut(): void
    {
        [, $compteur] = $this->nouveauCompte();
        [$capteur, $cle] = $this->creerCapteur($compteur);

        $this->postJson('/api/v1/device/status', ['statut' => 'offline'], $this->entetes($capteur->device_id, $cle))
            ->assertOk()
            ->assertJsonPath('statut', 'hors_ligne');

        $this->assertSame('hors_ligne', $capteur->fresh()->statut);
        $this->assertNotNull($capteur->fresh()->last_seen_at);
    }

    public function test_une_mesure_est_transmise_et_enregistree_par_api(): void
    {
        [, $compteur] = $this->nouveauCompte();
        [$capteur, $cle] = $this->creerCapteur($compteur);

        $this->postJson('/api/v1/device/mesures', [
            'tension' => 230,
            'courant' => 2,
            'puissance' => 460,
            'energie' => 12.5,
            'ts' => now()->timestamp,
        ], $this->entetes($capteur->device_id, $cle))->assertCreated();

        $this->assertSame(1, Mesure::query()->where('capteur_id', $capteur->id)->count());
    }

    public function test_les_commandes_relais_sont_recuperees_et_acquittees_par_api(): void
    {
        [$user, $compteur] = $this->nouveauCompte();
        [$relais, $cle] = $this->creerCapteur($compteur, 'esp32-test-relais', 'RELAIS');
        $equipement = Equipement::create([
            'compteur_id' => $compteur->id,
            'nom' => 'Ventilateur',
            'type' => 'autre',
            'puissance_nominale' => 80,
            'relais_capteur_id' => $relais->id,
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/equipements/{$equipement->id}/relais", ['action' => 'off'])
            ->assertAccepted()
            ->assertJsonPath('action', 'off');

        $poll = $this->getJson('/api/v1/device/commandes', $this->entetes($relais->device_id, $cle))
            ->assertOk()
            ->assertJsonCount(1, 'commandes')
            ->assertJsonPath('commandes.0.action', 'off');

        $commandeId = $poll->json('commandes.0.id');
        $this->assertDatabaseHas('commandes_appareil', [
            'id' => $commandeId,
            'capteur_id' => $relais->id,
            'statut' => CommandeAppareil::EN_ATTENTE,
        ]);

        $this->postJson("/api/v1/device/commandes/{$commandeId}/ack", ['statut' => CommandeAppareil::EXECUTEE],
            $this->entetes($relais->device_id, $cle))->assertOk()->assertJsonPath('acquittee', true);

        $this->getJson('/api/v1/device/commandes', $this->entetes($relais->device_id, $cle))
            ->assertOk()
            ->assertJsonCount(0, 'commandes');
        $this->assertDatabaseHas('commandes_appareil', [
            'id' => $commandeId,
            'statut' => CommandeAppareil::EXECUTEE,
        ]);
    }

    public function test_un_appareil_ne_peut_pas_acquitter_la_commande_dun_autre(): void
    {
        [, $compteur] = $this->nouveauCompte();
        [$relais, $cle] = $this->creerCapteur($compteur, 'esp32-relais-cible', 'RELAIS');
        [$autreAppareil, $autreCle] = $this->creerCapteur($compteur, 'esp32-autre');
        $equipement = Equipement::create([
            'compteur_id' => $compteur->id,
            'nom' => 'Lampe',
            'type' => 'eclairage',
            'puissance_nominale' => 20,
            'relais_capteur_id' => $relais->id,
        ]);
        $commande = CommandeAppareil::create([
            'capteur_id' => $relais->id,
            'equipement_id' => $equipement->id,
            'action' => 'on',
            'raison' => 'manuel',
            'statut' => CommandeAppareil::EN_ATTENTE,
        ]);

        $this->postJson("/api/v1/device/commandes/{$commande->id}/ack", ['statut' => CommandeAppareil::EXECUTEE],
            $this->entetes($autreAppareil->device_id, $autreCle))->assertNotFound();

        $this->assertDatabaseHas('commandes_appareil', ['id' => $commande->id, 'statut' => CommandeAppareil::EN_ATTENTE]);
    }
}
