<?php

namespace App\Http\Controllers\Api;

use App\Enums\TypeAlerte;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlerteResource;
use App\Models\Alerte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlerteController extends Controller
{
    /** consulter() : liste filtrable + compteurs des cartes d'en-tête. */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'statut' => ['sometimes', Rule::in([Alerte::NON_LUE, Alerte::LUE])],
            'type' => ['sometimes', Rule::enum(TypeAlerte::class)],
            'niveau' => ['sometimes', Rule::in(['info', 'warning', 'critique'])],
            'compteur_id' => ['sometimes', 'integer'],
            'du' => ['sometimes', 'date'], 'au' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $base = Alerte::query()->visibleTo($request->user())
            ->when($v['compteur_id'] ?? null, fn ($q, $id) => $q->where('compteur_id', $id));

        $liste = (clone $base)->with(['compteur:id,numero_compteur', 'capteur:id,device_id'])
            ->when($v['statut'] ?? null, fn ($q, $s) => $q->where('statut', $s))
            ->when($v['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($v['niveau'] ?? null, fn ($q, $n) => $q->where('niveau', $n))
            ->when($v['du'] ?? null, fn ($q, $d) => $q->where('date_heure', '>=', $this->date($d)->startOfDay()))
            ->when($v['au'] ?? null, fn ($q, $d) => $q->where('date_heure', '<=', $this->date($d)->endOfDay()))
            ->latest('date_heure')
            ->paginate($v['per_page'] ?? 20);

        $plusFrequent = (clone $base)->where('date_heure', '>=', now()->startOfMonth())
            ->selectRaw('type, COUNT(*) AS n')->groupBy('type')->orderByDesc('n')->first();

        return response()->json([
            'data' => AlerteResource::collection($liste)->resolve(),
            'meta' => [
                'page' => $liste->currentPage(), 'derniere_page' => $liste->lastPage(), 'total' => $liste->total(),
                'non_lues' => (clone $base)->nonLues()->count(),
                'cette_semaine' => (clone $base)->where('date_heure', '>=', now()->startOfWeek())->count(),
                'type_plus_frequent' => $plusFrequent ? [
                    'type' => $plusFrequent->type, 'libelle' => TypeAlerte::from($plusFrequent->type)->label(), 'occurrences' => (int) $plusFrequent->n,
                ] : null,
            ],
        ]);
    }

    public function show(Request $request, int $alerte): AlerteResource
    {
        return new AlerteResource(
            Alerte::query()->visibleTo($request->user())->with(['compteur:id,numero_compteur', 'capteur:id,device_id'])->findOrFail($alerte)
        );
    }

    /** marquerCommeLue() */
    public function lire(Request $request, int $alerte): AlerteResource
    {
        $a = Alerte::query()->visibleTo($request->user())->findOrFail($alerte);
        $a->marquerCommeLue();

        return new AlerteResource($a->refresh());
    }

    /** « Tout marquer comme lu » */
    public function lireTout(Request $request): JsonResponse
    {
        $n = Alerte::query()->visibleTo($request->user())->nonLues()
            ->when($request->integer('compteur_id'), fn ($q, $id) => $q->where('compteur_id', $id))
            ->update(['statut' => Alerte::LUE, 'lue_at' => now()]);

        return response()->json(['message' => "{$n} alerte(s) marquée(s) comme lue(s).", 'modifiees' => $n]);
    }
}
