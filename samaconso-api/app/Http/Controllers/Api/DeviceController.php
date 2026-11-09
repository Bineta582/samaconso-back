<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Capteur;
use App\Models\CommandeAppareil;
use App\Services\MesureIngestionService;
use App\Support\MesureInvalide;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * API HTTP destinée aux appareils : mesures, état, et récupération des commandes relais.
 */
class DeviceController extends Controller
{
    public function __construct(private readonly MesureIngestionService $ingestion) {}

    public function store(Request $request): JsonResponse
    {
        /** @var Capteur $capteur */
        $capteur = $request->attributes->get('capteur');

        try {
            $mesure = $this->ingestion->ingerer($capteur, $request->all());
        } catch (MesureInvalide $e) {
            return response()->json(['message' => 'Mesure rejetée : '.$e->getMessage()], 422);
        }

        return $mesure
            ? response()->json(['enregistree' => true], 201)
            : response()->json(['enregistree' => false, 'doublon' => true], 200);
    }

    public function lot(Request $request): JsonResponse
    {
        /** @var Capteur $capteur */
        $capteur = $request->attributes->get('capteur');
        $data = $request->validate([
            'mesures' => ['required', 'array', 'min:1', 'max:500'],
            'mesures.*' => ['array'],
        ]);

        return response()->json($this->ingestion->ingererLot($capteur, $data['mesures']));
    }

    public function statut(Request $request): JsonResponse
    {
        /** @var Capteur $capteur */
        $capteur = $request->attributes->get('capteur');
        $data = $request->validate([
            'statut' => ['required', Rule::in(['online', 'offline'])],
        ]);

        $capteur->forceFill([
            'statut' => $data['statut'] === 'online' ? 'actif' : 'hors_ligne',
            'last_seen_at' => now(),
        ])->save();

        return response()->json([
            'statut' => $capteur->statut,
            'last_seen_at' => $capteur->last_seen_at?->toIso8601String(),
        ]);
    }

    public function commandes(Request $request): JsonResponse
    {
        /** @var Capteur $capteur */
        $capteur = $request->attributes->get('capteur');

        $commandes = CommandeAppareil::query()
            ->where('capteur_id', $capteur->id)
            ->where('statut', CommandeAppareil::EN_ATTENTE)
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'equipement_id', 'action', 'raison', 'created_at']);

        return response()->json(['commandes' => $commandes]);
    }

    public function acquitterCommande(Request $request, int $commande): JsonResponse
    {
        /** @var Capteur $capteur */
        $capteur = $request->attributes->get('capteur');
        $data = $request->validate([
            'statut' => ['required', Rule::in([CommandeAppareil::EXECUTEE, CommandeAppareil::ECHOUEE])],
            'erreur' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($capteur, $commande, $data): void {
            $commandeAppareil = CommandeAppareil::query()
                ->where('capteur_id', $capteur->id)
                ->whereKey($commande)
                ->lockForUpdate()
                ->first();

            if (! $commandeAppareil) {
                throw (new ModelNotFoundException)->setModel(CommandeAppareil::class, [$commande]);
            }

            abort_unless(
                $commandeAppareil->statut === CommandeAppareil::EN_ATTENTE,
                409,
                'Cette commande a déjà été traitée.'
            );

            $commandeAppareil->update([
                'statut' => $data['statut'],
                'terminee_at' => now(),
                'erreur' => $data['statut'] === CommandeAppareil::ECHOUEE ? ($data['erreur'] ?? null) : null,
            ]);
        });

        return response()->json(['acquittee' => true, 'statut' => $data['statut']]);
    }
}
