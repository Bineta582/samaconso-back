<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MesureResource;
use App\Models\Mesure;
use App\Services\ConsommationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsommationController extends Controller
{
    public function __construct(private readonly ConsommationService $service)
    {
    }

    /** Valeurs instantanées + total du jour : l'interface rafraîchit cet appel toutes les 5 secondes. */
    public function live(Request $request, int $compteur): JsonResponse
    {
        $c = $this->compteur($request, $compteur);
        $jour = now()->startOfDay();

        return response()->json(['data' => [
            'instantane' => $this->service->instantane($c),
            'aujourdhui' => [
                'kwh' => round($this->service->energie($c, $jour, now()), 3),
                'cout_fcfa' => $this->service->cout($c, $jour, now()),
            ],
            'serveur_heure' => now()->toIso8601String(),
        ]]);
    }

    /** Courbe d'évolution filtrable par période (jour, semaine, mois, année), équipement et grandeur. */
    public function serie(Request $request, int $compteur): JsonResponse
    {
        $c = $this->compteur($request, $compteur);
        $v = $request->validate([
            'periode' => ['sometimes', Rule::in(ConsommationService::PERIODES)],
            'date' => ['sometimes', 'date'],
            'equipement_id' => ['sometimes', 'integer'],
            'metrique' => ['sometimes', Rule::in(array_keys(ConsommationService::METRIQUES))],
        ]);

        return response()->json(['data' => $this->service->serie(
            $c,
            $v['periode'] ?? 'mois',
            $this->date($v['date'] ?? null),
            $v['equipement_id'] ?? null,
            $v['metrique'] ?? 'energie',
        )]);
    }

    /** Diagramme de répartition par équipement. */
    public function repartition(Request $request, int $compteur): JsonResponse
    {
        $c = $this->compteur($request, $compteur);
        $v = $request->validate([
            'periode' => ['sometimes', Rule::in(ConsommationService::PERIODES)],
            'date' => ['sometimes', 'date'],
        ]);
        [$from, $to] = $this->service->bornes($v['periode'] ?? 'mois', $this->date($v['date'] ?? null));

        return response()->json(['data' => [
            'du' => $from->toDateString(),
            'au' => $to->copy()->subSecond()->toDateString(),
            'total_kwh' => round($this->service->energie($c, $from, $to), 3),
            'lignes' => $this->service->repartition($c, $from, $to),
        ]]);
    }

    /** Comparaison de deux périodes. */
    public function comparaison(Request $request, int $compteur): JsonResponse
    {
        $c = $this->compteur($request, $compteur);
        $v = $request->validate([
            'a_du' => ['required', 'date'], 'a_au' => ['required', 'date', 'after_or_equal:a_du'],
            'b_du' => ['required', 'date'], 'b_au' => ['required', 'date', 'after_or_equal:b_du'],
        ]);

        return response()->json(['data' => $this->service->comparer(
            $c, $this->date($v['a_du'])->startOfDay(), $this->date($v['a_au']),
            $this->date($v['b_du'])->startOfDay(), $this->date($v['b_au']),
        )]);
    }

    /** Histogramme comparatif mensuel. */
    public function mensuel(Request $request, int $compteur): JsonResponse
    {
        $c = $this->compteur($request, $compteur);
        $nb = $request->validate(['mois' => ['sometimes', 'integer', 'min:2', 'max:24']])['mois'] ?? 6;

        return response()->json(['data' => $this->service->comparaisonMensuelle($c, $nb)]);
    }

    /** Historique brut des mesures (tension, courant, puissance, énergie, fréquence), paginé. */
    public function mesures(Request $request, int $compteur): AnonymousResourceCollection
    {
        $c = $this->compteur($request, $compteur);
        $v = $request->validate([
            'du' => ['sometimes', 'date'], 'au' => ['sometimes', 'date'],
            'capteur_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $q = Mesure::query()->where('compteur_id', $c->id)->orderByDesc('date_heure');
        // Par défaut : mesures du capteur principal (consommation globale)
        $q->when($v['capteur_id'] ?? null,
            fn ($q, $id) => $q->where('capteur_id', $id),
            fn ($q) => $q->whereIn('capteur_id', $c->idsCapteursTotal()));
        $q->when($v['du'] ?? null, fn ($q, $d) => $q->where('date_heure', '>=', $this->date($d)->startOfDay()));
        $q->when($v['au'] ?? null, fn ($q, $d) => $q->where('date_heure', '<=', $this->date($d)->endOfDay()));

        return MesureResource::collection($q->paginate($v['per_page'] ?? 50));
    }

    /** Export CSV ou PDF des consommations horaires. */
    public function export(Request $request, int $compteur)
    {
        $c = $this->compteur($request, $compteur);
        $v = $request->validate([
            'format' => ['required', Rule::in(['csv', 'pdf'])],
            'du' => ['sometimes', 'date'],
            'au' => ['sometimes', 'date', 'after_or_equal:du'],
        ]);
        $from = $this->date($v['du'] ?? null, now()->startOfMonth())->startOfDay();
        $to = $this->date($v['au'] ?? null)->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, "La période d'export ne peut pas dépasser un an.");

        $lignes = $this->service->lignesExport($c, $from, $to);
        $nom = "consommation-{$c->numero_compteur}-{$from->toDateString()}_{$to->toDateString()}";

        if ($v['format'] === 'pdf') {
            return Pdf::loadView('exports.consommation', [
                'compteur' => $c, 'lignes' => $lignes, 'du' => $from, 'au' => $to,
                'total_kwh' => round(array_sum(array_column($lignes, 'energie_kwh')), 2),
                'cout_fcfa' => $this->service->cout($c, $from, $to),
            ])->download($nom.'.pdf');
        }

        return new StreamedResponse(function () use ($lignes) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF"); // BOM UTF-8 : accents corrects dans Excel
            fputcsv($f, ['heure', 'energie_kwh', 'puissance_moy_w', 'tension_moy_v', 'courant_moy_a'], ';');
            foreach ($lignes as $l) {
                fputcsv($f, $l, ';');
            }
            fclose($f);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nom}.csv\"",
        ]);
    }
}
