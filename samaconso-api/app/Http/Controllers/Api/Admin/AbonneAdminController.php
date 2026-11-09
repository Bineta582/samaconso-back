<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Profil;
use App\Enums\TypeAbonnement;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompteurResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** gererAbonnes() : liste, consultation, modification, suspension et suppression des comptes abonnés. */
class AbonneAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'statut' => ['sometimes', Rule::in(['actif', 'suspendu'])],
            'type_abonnement' => ['sometimes', Rule::enum(TypeAbonnement::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = User::query()->where('profil', Profil::Abonne->value)->with('abonne')
            ->when($v['q'] ?? null, function ($q, $terme) {
                $like = '%'.mb_strtolower($terme).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(nom) LIKE ?', [$like])->orWhereRaw('LOWER(prenom) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]));
            })
            ->when($v['statut'] ?? null, fn ($q, $s) => $q->where('statut', $s))
            ->when($v['type_abonnement'] ?? null, fn ($q, $t) => $q->whereHas('abonne', fn ($a) => $a->where('type_abonnement', $t)))
            ->latest()->paginate($v['per_page'] ?? 25);

        return response()->json([
            'data' => UserResource::collection($page)->resolve(),
            'meta' => ['page' => $page->currentPage(), 'derniere_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(int $user): JsonResponse
    {
        $u = $this->abonne($user)->load('abonne.compteurs');

        return response()->json(['data' => [
            'utilisateur' => new UserResource($u),
            'compteurs' => CompteurResource::collection($u->abonne->compteurs->load('abonne')),
        ]]);
    }

    public function update(Request $request, int $user): UserResource
    {
        $u = $this->abonne($user);
        $v = $request->validate([
            'nom' => ['sometimes', 'string', 'max:100'],
            'prenom' => ['sometimes', 'string', 'max:100'],
            'telephone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9\s\-]{8,18}$/'],
            'statut' => ['sometimes', Rule::in(['actif', 'suspendu'])],
            'type_abonnement' => ['sometimes', Rule::enum(TypeAbonnement::class)],
        ]);

        $u->update(collect($v)->except('type_abonnement')->all());
        if (isset($v['type_abonnement'])) {
            $u->abonne?->update(['type_abonnement' => $v['type_abonnement']]);
        }
        // Un compte suspendu ne peut plus renouveler son jeton : on ne révoque pas les jetons existants
        // (durée de vie courte, cf. JWT_TTL) — la reconnexion est refusée par AuthController::login.

        return new UserResource($u->refresh()->load('abonne'));
    }

    public function destroy(int $user): JsonResponse
    {
        $this->abonne($user)->delete(); // suppression logique (SoftDeletes)

        return response()->json(['message' => 'Compte supprimé.']);
    }

    private function abonne(int $id): User
    {
        return User::query()->where('profil', Profil::Abonne->value)->findOrFail($id);
    }
}
