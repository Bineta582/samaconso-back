<?php

namespace Tests\Feature;

use App\Models\SeuilAlerte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function donnees(array $surcharge = []): array
    {
        return array_merge([
            'nom' => 'Diop', 'prenom' => 'Awa', 'email' => 'awa@example.com', 'telephone' => '+221770000000',
            'password' => 'Secret1234', 'password_confirmation' => 'Secret1234',
            'type_abonnement' => 'menage', 'numero_compteur' => 'SN-0245871',
        ], $surcharge);
    }

    public function test_inscription_cree_compte_compteur_et_seuils_par_defaut(): void
    {
        $r = $this->postJson('/api/v1/auth/register', $this->donnees());

        $r->assertCreated()->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user' => ['id', 'email', 'profil']]);
        $this->assertDatabaseHas('compteurs', ['numero_compteur' => 'SN-0245871']);
        $this->assertGreaterThan(0, SeuilAlerte::count());
        // le mot de passe n'est jamais stocké en clair
        $this->assertNotSame('Secret1234', User::first()->password);
    }

    public function test_inscription_refuse_email_ou_compteur_deja_utilises(): void
    {
        $this->postJson('/api/v1/auth/register', $this->donnees())->assertCreated();

        $this->postJson('/api/v1/auth/register', $this->donnees())->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'numero_compteur']);
    }

    public function test_connexion_et_profil(): void
    {
        $this->postJson('/api/v1/auth/register', $this->donnees());

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'awa@example.com', 'password' => 'Secret1234'])
            ->assertOk()->json('access_token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'awa@example.com');
    }

    public function test_mauvais_mot_de_passe_et_route_protegee(): void
    {
        $this->postJson('/api/v1/auth/register', $this->donnees());

        $this->postJson('/api/v1/auth/login', ['email' => 'awa@example.com', 'password' => 'mauvais'])->assertUnauthorized();
        $this->getJson('/api/v1/compteurs')->assertUnauthorized();
    }

    public function test_compte_suspendu_ne_peut_pas_se_connecter(): void
    {
        $this->postJson('/api/v1/auth/register', $this->donnees());
        User::first()->update(['statut' => 'suspendu']);

        $this->postJson('/api/v1/auth/login', ['email' => 'awa@example.com', 'password' => 'Secret1234'])->assertForbidden();
    }
}
