<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompteurResource;
use App\Models\Compteur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CompteurController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Compteur::query()->visibleTo($request->user())->with('abonne')
            ->withCount(['capteurs', 'equipements'])->orderBy('id');

        if ($request->filled('statut')) {
            $q->where('statut', $request->string('statut'));
        }

        return CompteurResource::collection($request->user()->estAdmin() ? $q->paginate(25) : $q->get());
    }

    public function show(Request $request, int $compteur): CompteurResource
    {
        return new CompteurResource($this->compteur($request, $compteur)->loadCount(['capteurs', 'equipements']));
    }

    /** ajouterCompteur() — un abonné ajoute un compteur à son compte ; un admin précise l'abonné. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'abonne_id' => [Rule::requiredIf($user->estAdmin()), 'integer', 'exists:abonnes,id'],
            'numero_compteur' => ['required', 'string', 'regex:/^[A-Za-z0-9\-]{6,20}$/', 'unique:compteurs,numero_compteur'],
            'type_compteur' => ['sometimes', Rule::in(['woyofal', 'postpaye'])],
            'adresse' => ['nullable', 'string', 'max:255'],
            'date_installation' => ['nullable', 'date'],
            'objectif_mensuel_kwh' => ['nullable', 'numeric', 'min:1', 'max:100000'],
        ]);

        $abonneId = $user->estAdmin() ? $data['abonne_id'] : $user->abonne?->id;
        abort_if($abonneId === null, 403, 'Seul un abonné peut ajouter un compteur à son compte.');

        $compteur = Compteur::create([
            'abonne_id' => $abonneId,
            'numero_compteur' => strtoupper($data['numero_compteur']),
            'type_compteur' => $data['type_compteur'] ?? 'woyofal',
            'adresse' => $data['adresse'] ?? null,
            'date_installation' => $data['date_installation'] ?? null,
            'objectif_mensuel_kwh' => $data['objectif_mensuel_kwh'] ?? null,
            'statut' => 'actif',
            'solde_initial_at' => now(),
        ]);

        return (new CompteurResource($compteur->load('abonne')))->response()->setStatusCode(201);
    }

    /** modifier() — le statut n'est modifiable que par un administrateur. */
    public function update(Request $request, int $compteur): CompteurResource
    {
        $c = $this->compteur($request, $compteur);
        $data = $request->validate([
            'adresse' => ['sometimes', 'nullable', 'string', 'max:255'],
            'objectif_mensuel_kwh' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:100000'],
            'solde_initial_fcfa' => ['sometimes', 'numeric', 'min:0'],
            'statut' => ['sometimes', Rule::in(['actif', 'maintenance', 'hors_ligne', 'inactif'])],
        ]);

        if (isset($data['statut']) && ! $request->user()->estAdmin()) {
            abort(403, 'Seul un administrateur peut modifier le statut du compteur.');
        }
        // Recalage du solde de départ : on repart de ce solde à l'instant présent.
        if (isset($data['solde_initial_fcfa'])) {
            $data['solde_initial_at'] = now();
        }
        $c->update($data);

        return new CompteurResource($c->refresh()->load('abonne'));
    }

    /** supprimer() — réservé à l'administrateur (routes admin). */
    public function destroy(Request $request, int $compteur): JsonResponse
    {
        $this->compteur($request, $compteur)->delete();

        return response()->json(['message' => 'Compteur supprimé.']);
    }
}
