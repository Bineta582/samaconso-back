<?php

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Http\Resources\MoyenPaiementResource;
use App\Models\MoyenDePaiement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Moyens de paiement enregistrés. Aucune donnée de carte sensible n'est stockée :
 * ni numéro complet ni CVV — uniquement les 4 derniers chiffres et le jeton du prestataire.
 */
class MoyenPaiementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return MoyenPaiementResource::collection(
            $this->abonne($request)->moyensPaiement()->orderByDesc('par_defaut')->orderBy('id')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $abonne = $this->abonne($request);
        $v = $request->validate([
            'type' => ['required', Rule::enum(MoyenPaiement::class)],
            'libelle' => ['nullable', 'string', 'max:80'],
            'telephone' => ['required_unless:type,carte', 'nullable', 'string', 'regex:/^\+?[0-9\s\-]{8,18}$/'],
            'derniers_chiffres' => ['required_if:type,carte', 'nullable', 'digits:4'],
            'expiration' => ['required_if:type,carte', 'nullable', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
            'token_prestataire' => ['nullable', 'string', 'max:255'],
            'par_defaut' => ['sometimes', 'boolean'],
        ]);

        $moyen = DB::transaction(function () use ($abonne, $v) {
            $premier = ! $abonne->moyensPaiement()->exists();
            $defaut = $premier || ($v['par_defaut'] ?? false);
            if ($defaut) {
                $abonne->moyensPaiement()->update(['par_defaut' => false]);
            }

            return $abonne->moyensPaiement()->create($v + ['par_defaut' => $defaut]);
        });

        return (new MoyenPaiementResource($moyen))->response()->setStatusCode(201);
    }

    public function defaut(Request $request, int $moyen): MoyenPaiementResource
    {
        $abonne = $this->abonne($request);
        $m = $abonne->moyensPaiement()->findOrFail($moyen);
        DB::transaction(function () use ($abonne, $m) {
            $abonne->moyensPaiement()->update(['par_defaut' => false]);
            $m->update(['par_defaut' => true]);
        });

        return new MoyenPaiementResource($m->refresh());
    }

    public function destroy(Request $request, int $moyen): JsonResponse
    {
        $this->abonne($request)->moyensPaiement()->findOrFail($moyen)->delete();

        return response()->json(['message' => 'Moyen de paiement supprimé.']);
    }

    private function abonne(Request $request)
    {
        $abonne = $request->user()->abonne;
        abort_if($abonne === null, 403, 'Réservé aux abonnés.');

        return $abonne;
    }
}
