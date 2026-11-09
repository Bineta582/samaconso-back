<?php

namespace App\Http\Controllers\Api;

use App\Enums\TypeAbonnement;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfilController extends Controller
{
    /** modifierProfil() */
    public function update(Request $request): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'nom' => ['sometimes', 'string', 'max:100'],
            'prenom' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'telephone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9\s\-]{8,18}$/'],
            'type_abonnement' => ['sometimes', Rule::enum(TypeAbonnement::class)],
        ]);

        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }
        $user->update(collect($data)->except('type_abonnement')->all());

        if (isset($data['type_abonnement']) && $user->abonne) {
            $user->abonne->update(['type_abonnement' => $data['type_abonnement']]);
        }

        return new UserResource($user->refresh()->load('abonne'));
    }

    /** changerMotDePasse() — invalide l'ancien jeton et en délivre un nouveau. */
    public function changerMotDePasse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mot_de_passe_actuel' => ['required', 'string', 'current_password:api'],
            'password' => ['required', 'confirmed', 'different:mot_de_passe_actuel', Password::min(8)->letters()->numbers()],
        ]);

        $user = $request->user();
        $user->update(['password' => $data['password']]);

        auth('api')->logout();

        return response()->json([
            'message' => 'Mot de passe modifié.',
            'access_token' => auth('api')->login($user),
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->preferences()]);
    }

    public function majPreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['sometimes', 'boolean'],
            'sms' => ['sometimes', 'boolean'],
            'rapport_hebdomadaire' => ['sometimes', 'boolean'],
            'recommandations' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $user->update(['preferences_notifications' => array_merge($user->preferences(), $data)]);

        return response()->json(['data' => $user->refresh()->preferences()]);
    }
}
