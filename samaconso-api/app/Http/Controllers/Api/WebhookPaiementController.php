<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Paiement;
use App\Services\Payment\PaymentService;
use App\Services\Payment\ResultatPaiement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Notification asynchrone d'un prestataire (confirmation d'un paiement mobile money).
 * Authentifiée par signature HMAC-SHA256 du corps brut (en-tête X-Signature).
 * Adapter le nom de l'en-tête / l'algorithme à la documentation du prestataire retenu.
 */
class WebhookPaiementController extends Controller
{
    public function __invoke(Request $request, string $driver, PaymentService $service): JsonResponse
    {
        $secret = (string) config('services.paiement.webhook_secret');
        $attendu = hash_hmac('sha256', $request->getContent(), $secret);

        if ($secret === '' || ! hash_equals($attendu, (string) $request->header('X-Signature'))) {
            return response()->json(['message' => 'Signature invalide.'], 401);
        }

        $v = $request->validate([
            'reference' => ['required', 'string'],
            'statut' => ['required', Rule::in([ResultatPaiement::REUSSI, ResultatPaiement::ECHOUE])],
            'reference_prestataire' => ['nullable', 'string', 'max:255'],
            'erreur' => ['nullable', 'string', 'max:255'],
        ]);

        $paiement = Paiement::query()->where('reference', $v['reference'])->first();
        if (! $paiement) {
            return response()->json(['message' => 'Référence inconnue.'], 404);
        }

        $service->appliquer($paiement, new ResultatPaiement($v['statut'], $v['reference_prestataire'] ?? null, $v['erreur'] ?? null));

        return response()->json(['message' => 'ok']); // répondre 200 même si déjà traité (idempotence)
    }
}
