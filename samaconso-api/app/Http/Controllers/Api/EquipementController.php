<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EquipementResource;
use App\Models\Capteur;
use App\Models\Equipement;
use App\Services\ConsommationService;
use App\Services\RelayService;
use App\Support\TypesEquipement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EquipementController extends Controller
{
    public function __construct(private readonly ConsommationService $consommation) {}

    /** Liste + résumé de l'installation (page « Équipements »). */
    public function index(Request $request, int $compteur): JsonResponse
    {
        $c = $this->compteur($request, $compteur);
        $equipements = $c->equipements()->with('capteur')->orderBy('nom')->get();

        EquipementResource::$instantane = $this->consommation->instantaneParEquipement($c);

        $repartition = collect($this->consommation->repartition($c, now()->subDays(30), now()))
            ->firstWhere(fn ($l) => $l['equipement_id'] !== null);
        $avecCapteur = $equipements->filter(fn (Equipement $e) => $e->capteur !== null);

        return response()->json([
            'data' => EquipementResource::collection($equipements),
            'resume' => [
                'nombre' => $equipements->count(),
                'puissance_installee_w' => round($equipements->sum('puissance_nominale'), 1),
                'emplacements' => $equipements->pluck('emplacement')->filter()->unique()->count(),
                'plus_energivore' => $repartition ? ['nom' => $repartition['nom'], 'part_pct' => $repartition['pourcentage']] : null,
                'avec_capteur' => $avecCapteur->count(),
                'sans_capteur' => $equipements->count() - $avecCapteur->count(),
                'classement_puissance' => $equipements->sortByDesc('puissance_nominale')->take(4)
                    ->map(fn (Equipement $e) => ['nom' => $e->nom, 'puissance_w' => $e->puissance_nominale])->values(),
            ],
        ]);
    }

    /** ajouter() */
    public function store(Request $request, int $compteur): JsonResponse
    {
        $c = $this->compteur($request, $compteur);
        $data = $this->valider($request);
        $this->verifierRelais($c->id, $data['relais_capteur_id'] ?? null);

        $equipement = $c->equipements()->create($data);

        return (new EquipementResource($equipement->load('capteur')))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $equipement): EquipementResource
    {
        return new EquipementResource($this->trouver($request, $equipement)->load('capteur'));
    }

    /** modifier() */
    public function update(Request $request, int $equipement): EquipementResource
    {
        $e = $this->trouver($request, $equipement);
        $data = $this->valider($request, partiel: true);
        $this->verifierRelais($e->compteur_id, $data['relais_capteur_id'] ?? null);
        $e->update($data);

        return new EquipementResource($e->refresh()->load('capteur'));
    }

    /** supprimer() */
    public function destroy(Request $request, int $equipement): JsonResponse
    {
        $this->trouver($request, $equipement)->delete();

        return response()->json(['message' => 'Équipement supprimé.']);
    }

    /** Commande manuelle du relais associé (allumer / couper). */
    public function relais(Request $request, int $equipement, RelayService $relais): JsonResponse
    {
        $e = $this->trouver($request, $equipement)->load('relais');
        $action = $request->validate(['action' => ['required', Rule::in(['on', 'off'])]])['action'];

        if (! $e->relais) {
            return response()->json(['message' => "Aucun relais n'est associé à cet équipement."], 422);
        }

        return $relais->commander($e, $action, 'manuel')
            ? response()->json(['message' => 'Commande enregistrée, en attente de l’appareil.', 'action' => $action], 202)
            : response()->json(['message' => 'Impossible d’enregistrer la commande.'], 409);
    }

    private function trouver(Request $request, int $id): Equipement
    {
        return Equipement::query()->visibleTo($request->user())->findOrFail($id);
    }

    private function valider(Request $request, bool $partiel = false): array
    {
        $p = $partiel ? 'sometimes' : 'required';

        return $request->validate([
            'nom' => [$p, 'string', 'max:120'],
            'type' => [$p, Rule::in(TypesEquipement::LISTE)],
            'emplacement' => ['sometimes', 'nullable', 'string', 'max:120'],
            'puissance_nominale' => [$p, 'numeric', 'min:0', 'max:50000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'coupure_auto_w' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:50000'],
            'relais_capteur_id' => ['sometimes', 'nullable', 'integer'],
        ]);
    }

    /** Le relais doit être un capteur RELAIS du même compteur. */
    private function verifierRelais(int $compteurId, ?int $capteurId): void
    {
        if ($capteurId === null) {
            return;
        }
        $ok = Capteur::query()->where('id', $capteurId)->where('compteur_id', $compteurId)
            ->where('type_capteur', 'RELAIS')->exists();
        abort_unless($ok, 422, "Le capteur indiqué n'est pas un relais de ce compteur.");
    }
}
