<?php

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaiementResource;
use App\Models\MoyenDePaiement;
use App\Models\Paiement;
use App\Services\CreditService;
use App\Services\Payment\PaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Page « Facturation & recharge » : solde Woyofal, recharge, historique, reçus. */
class FacturationController extends Controller
{
    public function __construct(
        private readonly CreditService $credit,
        private readonly PaymentService $paiements,
    ) {
    }

    /** Solde actuel, autonomie estimée, dernière recharge, dépenses du mois. */
    public function solde(Request $request, int $compteur): JsonResponse
    {
        $s = $this->credit->solde($this->compteur($request, $compteur));
        $s['derniere_recharge'] = $s['derniere_recharge'] ? new PaiementResource($s['derniere_recharge']) : null;

        return response()->json(['data' => $s]);
    }

    /** effectuerPaiement() — recharge du compteur. */
    public function recharger(Request $request, int $compteur): JsonResponse
    {
        $c = $this->compteur($request, $compteur);
        $abonne = $request->user()->abonne;
        abort_if($abonne === null, 403, 'Seul un abonné peut recharger un compteur.');
        abort_unless($c->abonne_id === $abonne->id, 404);

        $v = $request->validate([
            'montant' => ['required', 'numeric', 'min:1'],
            'moyen' => ['required_without:moyen_paiement_id', Rule::enum(MoyenPaiement::class)],
            'moyen_paiement_id' => ['nullable', 'integer'],
            'telephone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{8,18}$/'],
        ]);

        $enregistre = null;
        if (! empty($v['moyen_paiement_id'])) {
            $enregistre = MoyenDePaiement::query()->where('abonne_id', $abonne->id)->findOrFail($v['moyen_paiement_id']);
        }
        $moyen = $enregistre?->type ?? MoyenPaiement::from($v['moyen']);
        $telephone = $v['telephone'] ?? $enregistre?->telephone;

        abort_if($moyen->requiertTelephone() && ! $telephone, 422, 'Un numéro de téléphone est requis pour ce moyen de paiement.');

        $paiement = $this->paiements->recharger($abonne, $c, (float) $v['montant'], $moyen, $telephone, $enregistre);

        return (new PaiementResource($paiement))->response()->setStatusCode(
            $paiement->statut === StatutPaiement::Echoue ? 402 : 201
        );
    }

    /** Historique des transactions. */
    public function index(Request $request): JsonResponse
    {
        $abonne = $request->user()->abonne;
        abort_if($abonne === null, 403, 'Réservé aux abonnés.');

        $v = $request->validate([
            'statut' => ['sometimes', Rule::enum(StatutPaiement::class)],
            'compteur_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Paiement::query()->where('abonne_id', $abonne->id)
            ->when($v['statut'] ?? null, fn ($q, $s) => $q->where('statut', $s))
            ->when($v['compteur_id'] ?? null, fn ($q, $id) => $q->where('compteur_id', $id))
            ->latest()->paginate($v['per_page'] ?? 20);

        return response()->json([
            'data' => PaiementResource::collection($page)->resolve(),
            'meta' => ['page' => $page->currentPage(), 'derniere_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, int $paiement): PaiementResource
    {
        return new PaiementResource($this->mien($request, $paiement));
    }

    /** Reçu PDF d'un paiement réussi. */
    public function recu(Request $request, int $paiement)
    {
        $p = $this->mien($request, $paiement)->load('compteur', 'abonne.user');
        abort_unless($p->statut === StatutPaiement::Reussi, 422, "Aucun reçu : le paiement n'a pas abouti.");

        return Pdf::loadView('exports.recu', ['p' => $p])->download("recu-{$p->reference}.pdf");
    }

    private function mien(Request $request, int $id): Paiement
    {
        $abonne = $request->user()->abonne;
        abort_if($abonne === null, 403, 'Réservé aux abonnés.');

        return Paiement::query()->where('abonne_id', $abonne->id)->findOrFail($id);
    }
}
