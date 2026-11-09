<?php

namespace App\Http\Controllers\Api;

use App\Enums\TypeAlerte;
use App\Http\Controllers\Controller;
use App\Models\SeuilAlerte;
use App\Services\AlertEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Seuils d'alerte personnalisables (page « Seuils d'alerte »). Réservé aux abonnés. */
class SeuilAlerteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $abonne = $request->user()->abonne;
        abort_if($abonne === null, 403, 'Réservé aux abonnés.');

        $existants = $abonne->seuils()->whereNull('equipement_id')->get()->keyBy(fn ($s) => $s->type->value);
        $defauts = TypeAlerte::seuilsParDefaut();

        // Un seuil par type configurable ; valeur par défaut proposée si l'abonné n'en a pas défini.
        $lignes = collect(TypeAlerte::cases())
            ->reject(fn (TypeAlerte $t) => $t === TypeAlerte::Anomalie)
            ->map(fn (TypeAlerte $t) => [
                'type' => $t->value,
                'libelle' => $t->label(),
                'unite' => $t->unite(),
                'valeur' => $existants[$t->value]->valeur ?? ($defauts[$t->value] ?? null),
                'actif' => $existants[$t->value]->actif ?? false,
            ])->values();

        $parEquipement = $abonne->seuils()->whereNotNull('equipement_id')->get()->map(fn ($s) => [
            'type' => $s->type->value, 'equipement_id' => $s->equipement_id, 'valeur' => $s->valeur, 'actif' => $s->actif,
        ]);

        return response()->json(['data' => $lignes, 'par_equipement' => $parEquipement]);
    }

    public function update(Request $request): JsonResponse
    {
        $abonne = $request->user()->abonne;
        abort_if($abonne === null, 403, 'Réservé aux abonnés.');

        $data = $request->validate([
            'seuils' => ['required', 'array', 'min:1', 'max:50'],
            'seuils.*.type' => ['required', Rule::enum(TypeAlerte::class)],
            'seuils.*.valeur' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'seuils.*.actif' => ['sometimes', 'boolean'],
            'seuils.*.equipement_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        foreach ($data['seuils'] as $s) {
            $equipementId = $s['equipement_id'] ?? null;
            if ($equipementId !== null) {
                abort_unless($this->equipementDeLAbonne($abonne->id, $equipementId), 422, 'Équipement inconnu.');
            }
            SeuilAlerte::updateOrCreate(
                ['abonne_id' => $abonne->id, 'type' => $s['type'], 'equipement_id' => $equipementId],
                ['valeur' => $s['valeur'], 'actif' => $s['actif'] ?? true]
            );
        }
        AlertEngine::oublierSeuils($abonne->id);

        return $this->index($request);
    }

    private function equipementDeLAbonne(int $abonneId, int $equipementId): bool
    {
        return \App\Models\Equipement::query()->whereKey($equipementId)
            ->whereHas('compteur', fn ($q) => $q->where('abonne_id', $abonneId))->exists();
    }
}
