<?php

namespace App\Http\Controllers\Api;

use App\Enums\Profil;
use App\Enums\TypeAbonnement;
use App\Enums\TypeAlerte;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Abonne;
use App\Models\Compteur;
use App\Models\SeuilAlerte;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /** Inscription d'un abonné (ménage ou petite entreprise) rattaché à son compteur SENELEC. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'telephone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{8,18}$/'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'type_abonnement' => ['required', Rule::enum(TypeAbonnement::class)],
            'numero_compteur' => ['required', 'string', 'regex:/^[A-Za-z0-9\-]{6,20}$/', 'unique:compteurs,numero_compteur'],
            'numero_contrat' => ['nullable', 'string', 'max:40', 'unique:abonnes,numero_contrat'],
            'adresse' => ['nullable', 'string', 'max:255'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'nom' => $data['nom'],
                'prenom' => $data['prenom'],
                'email' => strtolower($data['email']),
                'telephone' => $data['telephone'] ?? null,
                'password' => $data['password'], // haché (bcrypt) par le cast « hashed »
                'profil' => Profil::Abonne,
                'statut' => 'actif',
            ]);

            $abonne = Abonne::create([
                'user_id' => $user->id,
                'type_abonnement' => $data['type_abonnement'],
                'numero_contrat' => $data['numero_contrat'] ?? null,
            ]);

            Compteur::create([
                'abonne_id' => $abonne->id,
                'numero_compteur' => strtoupper($data['numero_compteur']),
                'type_compteur' => 'woyofal',
                'adresse' => $data['adresse'] ?? null,
                'statut' => 'actif',
                'solde_initial_at' => now(),
            ]);

            // Seuils d'alerte par défaut (modifiables dans Paramètres → Alertes)
            foreach (TypeAlerte::seuilsParDefaut() as $type => $valeur) {
                SeuilAlerte::create(['abonne_id' => $abonne->id, 'type' => $type, 'valeur' => $valeur, 'actif' => true]);
            }

            return $user;
        });

        return $this->reponseJeton(auth('api')->login($user), $user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $credentials['email'] = strtolower($credentials['email']);

        if (! $token = auth('api')->attempt($credentials)) {
            return response()->json(['message' => 'Identifiants incorrects.'], 401);
        }

        $user = auth('api')->user();
        if (! $user->estActif()) {
            auth('api')->logout();

            return response()->json(['message' => 'Ce compte est suspendu. Contactez le support.'], 403);
        }
        $user->forceFill(['last_login_at' => now()])->save();

        return $this->reponseJeton($token, $user);
    }

    public function logout(): JsonResponse
    {
        auth('api')->logout(); // le jeton est placé sur liste noire jusqu'à son expiration

        return response()->json(['message' => 'Déconnexion réussie.']);
    }

    public function refresh(): JsonResponse
    {
        return $this->reponseJeton(auth('api')->refresh(), auth('api')->user());
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('abonne'));
    }

    private function reponseJeton(string $token, User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60, // secondes : expiration automatique
            'user' => new UserResource($user->load('abonne')),
        ], $status);
    }
}
