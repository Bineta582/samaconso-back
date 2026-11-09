<?php

namespace App\Http\Controllers\Api;

use App\Enums\TypeCapteur;
use App\Http\Controllers\Controller;
use App\Http\Resources\CapteurResource;
use App\Models\Capteur;
use App\Models\Equipement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CapteurController extends Controller
{
    public function index(Request $request, int $compteur): AnonymousResourceCollection
    {
        return CapteurResource::collection($this->compteur($request, $compteur)->capteurs()->orderBy('id')->get());
    }

    /** Enregistrement d'un capteur (administrateur). La clé API HTTP n'est affichée qu'une fois. */
    public function store(Request $request, int $compteur): JsonResponse
    {
        $c = $this->compteur($request, $compteur);
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/', 'unique:capteurs,device_id'],
            'type_capteur' => ['required', Rule::enum(TypeCapteur::class)],
            'emplacement' => ['nullable', 'string', 'max:120'],
            'statut' => ['sometimes', Rule::in(['actif', 'inactif'])],
            'date_installation' => ['nullable', 'date'],
            'equipement_id' => ['nullable', 'integer'],
        ]);
        $this->verifierEquipement($c->id, $data['equipement_id'] ?? null);

        $cle = Str::random(40);
        $capteur = $c->capteurs()->create($data + ['api_key_hash' => Capteur::hacherCle($cle)]);

        return response()->json([
            'data' => new CapteurResource($capteur),
            'cle_api' => $cle,
            'avertissement' => 'Conservez cette clé : elle ne sera plus affichée.',
        ], 201);
    }

    public function update(Request $request, int $capteur): CapteurResource
    {
        $cap = Capteur::query()->visibleTo($request->user())->findOrFail($capteur);
        $data = $request->validate([
            'emplacement' => ['sometimes', 'nullable', 'string', 'max:120'],
            'statut' => ['sometimes', Rule::in(['actif', 'inactif', 'hors_ligne'])],
            'date_installation' => ['sometimes', 'nullable', 'date'],
            'equipement_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $this->verifierEquipement($cap->compteur_id, $data['equipement_id'] ?? null, $cap->id);
        $cap->update($data);

        return new CapteurResource($cap->refresh());
    }

    public function destroy(Request $request, int $capteur): JsonResponse
    {
        Capteur::query()->visibleTo($request->user())->findOrFail($capteur)->delete();

        return response()->json(['message' => 'Capteur supprimé.']);
    }

    public function regenererCle(Request $request, int $capteur): JsonResponse
    {
        $cap = Capteur::query()->visibleTo($request->user())->findOrFail($capteur);
        $cle = Str::random(40);
        $cap->update(['api_key_hash' => Capteur::hacherCle($cle)]);

        return response()->json(['cle_api' => $cle, 'avertissement' => 'Conservez cette clé : elle ne sera plus affichée.']);
    }

    /** « Configurer le capteur » (abonné) : associe / dissocie un capteur de mesure à un équipement. */
    public function associer(Request $request, int $capteur): CapteurResource
    {
        $cap = Capteur::query()->visibleTo($request->user())->findOrFail($capteur);
        abort_unless(in_array($cap->type_capteur, [TypeCapteur::Sct->value, TypeCapteur::Pzem->value], true), 422,
            "Seul un capteur de mesure (SCT-013 / PZEM-004T) peut suivre un équipement.");

        $equipementId = $request->validate(['equipement_id' => ['nullable', 'integer']])['equipement_id'] ?? null;
        $this->verifierEquipement($cap->compteur_id, $equipementId, $cap->id);
        $cap->update(['equipement_id' => $equipementId]);

        return new CapteurResource($cap->refresh());
    }

    private function verifierEquipement(int $compteurId, ?int $equipementId, ?int $capteurCourant = null): void
    {
        if ($equipementId === null) {
            return;
        }
        abort_unless(Equipement::query()->where('id', $equipementId)->where('compteur_id', $compteurId)->exists(), 422,
            "L'équipement n'appartient pas à ce compteur.");

        $pris = Capteur::query()->where('equipement_id', $equipementId)
            ->when($capteurCourant, fn ($q) => $q->where('id', '!=', $capteurCourant))->exists();
        abort_if($pris, 422, 'Cet équipement est déjà suivi par un autre capteur.');
    }
}
