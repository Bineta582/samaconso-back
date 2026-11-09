<?php

namespace Tests\Feature;

use App\Models\Alerte;
use App\Models\Mesure;
use App\Models\SeuilAlerte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\CreeDonnees;
use Tests\TestCase;

class IngestionMesuresTest extends TestCase
{
    use CreeDonnees, RefreshDatabase;

    private function entetes(string $deviceId, string $cle): array
    {
        return ['X-Device-Id' => $deviceId, 'X-Device-Key' => $cle];
    }

    public function test_une_mesure_valide_est_enregistree(): void
    {
        [, , $compteur] = $this->creerAbonne();
        [$capteur, $cle] = $this->creerCapteur($compteur);

        $this->postJson('/api/v1/device/mesures', [
            'tension' => 230.4, 'courant' => 4.8, 'puissance' => 1103, 'energie' => 12.5, 'frequence' => 50.0, 'ts' => now()->timestamp,
        ], $this->entetes($capteur->device_id, $cle))->assertCreated();

        $this->assertDatabaseHas('mesures', ['capteur_id' => $capteur->id, 'puissance' => 1103]);
        $this->assertNotNull($capteur->fresh()->last_seen_at);
    }

    public function test_cle_incorrecte_refusee(): void
    {
        [, , $compteur] = $this->creerAbonne();
        [$capteur] = $this->creerCapteur($compteur);

        $this->postJson('/api/v1/device/mesures', ['puissance' => 100], $this->entetes($capteur->device_id, 'mauvaise'))->assertUnauthorized();
    }

    public function test_doublon_ignore_et_valeur_aberrante_rejetee(): void
    {
        [, , $compteur] = $this->creerAbonne();
        [$capteur, $cle] = $this->creerCapteur($compteur);
        $h = $this->entetes($capteur->device_id, $cle);
        $ts = now()->subMinute()->timestamp;

        $this->postJson('/api/v1/device/mesures', ['puissance' => 500, 'ts' => $ts], $h)->assertCreated();
        $this->postJson('/api/v1/device/mesures', ['puissance' => 500, 'ts' => $ts], $h)->assertOk()->assertJsonPath('doublon', true);
        $this->postJson('/api/v1/device/mesures', ['tension' => 9999, 'ts' => $ts + 5], $h)->assertStatus(422);

        $this->assertSame(1, Mesure::count());
    }

    public function test_lot_de_mesures_tamponnees(): void
    {
        [, , $compteur] = $this->creerAbonne();
        [$capteur, $cle] = $this->creerCapteur($compteur);
        $base = now()->subHours(3)->startOfHour()->timestamp;

        $lot = collect(range(0, 4))->map(fn ($i) => ['puissance' => 400 + $i, 'energie' => 10 + $i / 100, 'ts' => $base + $i * 5])->all();
        $lot[] = ['puissance' => 400, 'ts' => $base]; // doublon
        $lot[] = ['frequence' => 5, 'puissance' => 1, 'ts' => $base + 100]; // fréquence hors plage

        $r = $this->postJson('/api/v1/device/mesures/batch', ['mesures' => $lot], $this->entetes($capteur->device_id, $cle))->assertOk();

        $r->assertJsonPath('inserees', 5)->assertJsonPath('doublons', 1)->assertJsonPath('rejetees', 1);
        // les mesures anciennes déclenchent l'agrégation horaire
        $this->assertDatabaseHas('consommations_horaires', ['capteur_id' => $capteur->id]);
    }

    public function test_depassement_de_seuil_cree_une_alerte_et_notifie(): void
    {
        Notification::fake();
        [$user, $abonne, $compteur] = $this->creerAbonne();
        [$capteur, $cle] = $this->creerCapteur($compteur);
        SeuilAlerte::create(['abonne_id' => $abonne->id, 'type' => 'pic_consommation', 'valeur' => 1800, 'actif' => true]);

        $this->postJson('/api/v1/device/mesures', ['puissance' => 2100, 'tension' => 230, 'ts' => now()->timestamp],
            $this->entetes($capteur->device_id, $cle))->assertCreated();

        $this->assertDatabaseHas('alertes', ['compteur_id' => $compteur->id, 'type' => 'pic_consommation', 'statut' => Alerte::NON_LUE]);
        Notification::assertSentTo($user, \App\Notifications\AlerteDeclenchee::class);

        // anti-spam : une 2e mesure dans la fenêtre de refroidissement ne recrée pas d'alerte
        $this->postJson('/api/v1/device/mesures', ['puissance' => 2200, 'ts' => now()->timestamp + 1],
            $this->entetes($capteur->device_id, $cle))->assertCreated();
        $this->assertSame(1, Alerte::count());
    }
}
