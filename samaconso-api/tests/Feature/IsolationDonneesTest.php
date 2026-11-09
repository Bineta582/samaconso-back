<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreeDonnees;
use Tests\TestCase;

/** Un abonné ne doit jamais voir les données d'un autre ; l'administrateur voit tout. */
class IsolationDonneesTest extends TestCase
{
    use CreeDonnees, RefreshDatabase;

    public function test_un_abonne_ne_voit_pas_le_compteur_d_un_autre(): void
    {
        [$userA, , $compteurA] = $this->creerAbonne('SN-0000001');
        [, , $compteurB] = $this->creerAbonne('SN-0000002');

        $this->actingAs($userA, 'api')->getJson("/api/v1/compteurs/{$compteurA->id}")->assertOk();
        $this->actingAs($userA, 'api')->getJson("/api/v1/compteurs/{$compteurB->id}")->assertNotFound();
        $this->actingAs($userA, 'api')->getJson("/api/v1/compteurs/{$compteurB->id}/live")->assertNotFound();
        $this->actingAs($userA, 'api')->getJson('/api/v1/compteurs')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_un_abonne_ne_peut_pas_utiliser_les_routes_admin(): void
    {
        [$user] = $this->creerAbonne();

        $this->actingAs($user, 'api')->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_l_administrateur_voit_tous_les_compteurs(): void
    {
        $this->creerAbonne('SN-0000001');
        $this->creerAbonne('SN-0000002');
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin, 'api')->getJson('/api/v1/compteurs')->assertOk()->assertJsonCount(2, 'data');
    }
}
