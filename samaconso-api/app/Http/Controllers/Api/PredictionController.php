<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PredictionResource;
use App\Http\Resources\RecommandationResource;
use App\Models\Prediction;
use App\Models\Recommandation;
use App\Services\PredictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PredictionController extends Controller
{
    public function __construct(private readonly PredictionService $service)
    {
    }

    /** consulter() : dernières prévisions à 7 et 30 jours + projection de fin de mois. */
    public function index(Request $request, int $compteur): JsonResponse
    {
        $c = $this->compteur($request, $compteur);
        $derniere = fn (string $p) => Prediction::query()->where('compteur_id', $c->id)->where('periode', $p)
            ->latest('date_prediction')->first();
        $p7 = $derniere('7_jours');
        $p30 = $derniere('30_jours');

        return response()->json(['data' => [
            '7_jours' => $p7 ? new PredictionResource($p7) : null,
            '30_jours' => $p30 ? new PredictionResource($p30) : null,
            'projection_fin_mois' => $this->service->projectionFinDeMois($c),
            'derniere_analyse' => ($p30 ?? $p7)?->date_prediction->toIso8601String(),
        ]]);
    }

    /** analyserDonnees() + genererPrediction() — « Relancer l'analyse ». */
    public function generer(Request $request, int $compteur): JsonResponse
    {
        $c = $this->compteur($request, $compteur);
        $resultat = $this->service->generer($c);

        return response()->json(['data' => [
            '7_jours' => new PredictionResource($resultat[7]),
            '30_jours' => new PredictionResource($resultat[30]),
        ]], 201);
    }

    public function recommandations(Request $request, int $compteur): JsonResponse
    {
        $c = $this->compteur($request, $compteur);

        return response()->json(['data' => RecommandationResource::collection(
            Recommandation::query()->where('compteur_id', $c->id)->orderByDesc('impact_estime_fcfa')->get()
        )]);
    }
}
