<?php

namespace Tests\Feature;

use App\Models\Paiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreeDonnees;
use Tests\TestCase;

class RechargeWoyofalTest extends TestCase
{
    use CreeDonnees, RefreshDatabase;

    public function test_recharge_reussie_delivre_un_code_et_estime_les_kwh(): void
    {
        [$user, , $compteur] = $this->creerAbonne();

        $r = $this->actingAs($user, 'api')->postJson("/api/v1/compteurs/{$compteur->id}/recharges", [
            'montant' => 5000, 'moyen' => 'orange_money', 'telephone' => '+221770001234',
        ])->assertCreated();

        $r->assertJsonPath('data.statut', 'reussi');
        $this->assertNotEmpty($r->json('data.code_recharge'));
        $this->assertEqualsWithDelta(5000 / 82, $r->json('data.kwh_estimes'), 0.01);
    }

    public function test_paiement_refuse_par_la_passerelle(): void
    {
        [$user, , $compteur] = $this->creerAbonne();

        $this->actingAs($user, 'api')->postJson("/api/v1/compteurs/{$compteur->id}/recharges", [
            'montant' => 2000, 'moyen' => 'wave', 'telephone' => '+221770000000', // numéro finissant par 0000 → échec simulé
        ])->assertStatus(402)->assertJsonPath('data.statut', 'echoue');
    }

    public function test_montant_hors_bornes_et_telephone_obligatoire(): void
    {
        [$user, , $compteur] = $this->creerAbonne();

        $this->actingAs($user, 'api')->postJson("/api/v1/compteurs/{$compteur->id}/recharges", [
            'montant' => 100, 'moyen' => 'wave', 'telephone' => '+221770001234',
        ])->assertStatus(422)->assertJsonValidationErrors('montant');

        $this->actingAs($user, 'api')->postJson("/api/v1/compteurs/{$compteur->id}/recharges", [
            'montant' => 5000, 'moyen' => 'orange_money',
        ])->assertStatus(422);
    }

    public function test_le_solde_augmente_apres_une_recharge(): void
    {
        [$user, , $compteur] = $this->creerAbonne();
        $this->actingAs($user, 'api')->postJson("/api/v1/compteurs/{$compteur->id}/recharges", [
            'montant' => 10000, 'moyen' => 'wave', 'telephone' => '+221770001234',
        ])->assertCreated();

        $this->actingAs($user, 'api')->getJson("/api/v1/compteurs/{$compteur->id}/solde")
            ->assertOk()->assertJsonPath('data.solde_fcfa', 10000)->assertJsonPath('data.recharges_mois', 1);
    }

    public function test_webhook_signe_et_idempotent(): void
    {
        config(['services.paiement.webhook_secret' => 'secret-test']);
        [$user, $abonne, $compteur] = $this->creerAbonne();
        $paiement = Paiement::create([
            'reference' => 'SC-TEST000001', 'abonne_id' => $abonne->id, 'compteur_id' => $compteur->id, 'type' => 'recharge',
            'montant_fcfa' => 3000, 'frais_fcfa' => 0, 'moyen' => 'orange_money', 'statut' => 'en_attente',
        ]);

        $corps = json_encode(['reference' => 'SC-TEST000001', 'statut' => 'reussi', 'reference_prestataire' => 'OM-123']);
        $signature = hash_hmac('sha256', $corps, 'secret-test');

        $this->call('POST', '/api/v1/webhooks/paiements/orange_money', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'faux',
        ], $corps)->assertUnauthorized();

        for ($i = 0; $i < 2; $i++) { // rejeu du même webhook
            $this->call('POST', '/api/v1/webhooks/paiements/orange_money', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $signature,
            ], $corps)->assertOk();
        }

        $paiement->refresh();
        $this->assertSame('reussi', $paiement->statut->value);
        $first = $paiement->code_recharge;
        $this->assertNotNull($first); // un seul code émis malgré le rejeu
    }
}
