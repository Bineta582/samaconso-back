<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Profil;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlerteResource;
use App\Http\Resources\CapteurResource;
use App\Models\Abonne;
use App\Models\Alerte;
use App\Models\Capteur;
use App\Models\Compteur;
use App\Models\User;
use App\Services\ConsommationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Supervision de la plateforme (profil administrateur) — consulterStatistiques() / consulterAlertes(). */
class SupervisionController extends Controller
{
    public function __construct(private readonly ConsommationService $conso)
    {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $periode = $request->validate(['periode' => ['sometimes', Rule::in(ConsommationService::PERIODES)]])['periode'] ?? 'mois';
        $limiteEnLigne = now()->subMinutes((int) config('senelec.capteur_hors_ligne_minutes'));

        $abonnes = User::query()->where('profil', Profil::Abonne->value);
        $totalCompteurs = Compteur::count();
        $totalCapteurs = Capteur::count();
        $capteursActifs = Capteur::query()->where('statut', 'actif')->where('last_seen_at', '>=', $limiteEnLigne)->count();

        $parType = Capteur::query()
            ->selectRaw('type_capteur, COUNT(*) AS total, SUM(CASE WHEN statut = ? AND last_seen_at >= ? THEN 1 ELSE 0 END) AS actifs',
                ['actif', $limiteEnLigne])
            ->groupBy('type_capteur')->get()
            ->map(fn ($r) => ['type' => $r->type_capteur, 'total' => (int) $r->total, 'actifs' => (int) $r->actifs]);

        $types = Abonne::query()->selectRaw('type_abonnement, COUNT(*) AS n')->groupBy('type_abonnement')->pluck('n', 'type_abonnement');
        $totalAbonnes = max(1, (int) $types->sum());

        $recents = User::query()->where('profil', Profil::Abonne->value)->latest()->limit(5)
            ->with('abonne.compteurs')->get()->map(function (User $u) {
                $compteur = $u->abonne?->compteurs->first();
                $alerte = $compteur && Alerte::query()->where('compteur_id', $compteur->id)->nonLues()->exists();

                return [
                    'id' => $u->id,
                    'nom_complet' => $u->nomComplet(),
                    'email' => $u->email,
                    'compteur' => $compteur?->numero_compteur,
                    'consommation_mois_kwh' => $compteur ? round($this->conso->energie($compteur, now()->startOfMonth(), now()), 1) : null,
                    'statut' => match (true) {
                        $u->statut !== 'actif' => 'suspendu',
                        $compteur?->statut === 'maintenance' => 'maintenance',
                        $alerte => 'alerte',
                        default => 'actif',
                    },
                ];
            });

        return response()->json(['data' => [
            'kpis' => [
                'abonnes_actifs' => (clone $abonnes)->where('statut', 'actif')->count(),
                'nouveaux_abonnes_mois' => (clone $abonnes)->where('created_at', '>=', now()->startOfMonth())->count(),
                'compteurs_connectes' => Compteur::query()->where('statut', 'actif')->count(),
                'compteurs_total' => $totalCompteurs,
                'capteurs_actifs' => $capteursActifs,
                'capteurs_total' => $totalCapteurs,
                'disponibilite_capteurs_pct' => $totalCapteurs ? round($capteursActifs / $totalCapteurs * 100, 1) : null,
                'alertes_non_traitees' => Alerte::query()->nonLues()->count(),
                'alertes_critiques' => Alerte::query()->nonLues()->where('niveau', 'critique')->count(),
            ],
            'consommation_reseau' => $this->conso->serieReseau($periode, now()),
            'abonnes_recents' => $recents,
            'etat_capteurs' => $parType,
            'alertes_globales' => AlerteResource::collection(
                Alerte::query()->nonLues()->with(['compteur.abonne.user', 'capteur:id,device_id'])->latest('date_heure')->limit(5)->get()
            ),
            'repartition_abonnes' => $types->map(fn ($n, $type) => [
                'type' => $type, 'nombre' => (int) $n, 'pourcentage' => round($n / $totalAbonnes * 100, 1),
            ])->values(),
            'compteurs_par_statut' => Compteur::query()->selectRaw('statut, COUNT(*) AS n')->groupBy('statut')->pluck('n', 'statut'),
            'derniere_synchronisation' => Capteur::query()->max('last_seen_at'),
        ]]);
    }

    /** gererCapteurs() : vue globale du parc. */
    public function capteurs(Request $request): JsonResponse
    {
        $v = $request->validate([
            'type_capteur' => ['sometimes', 'string'], 'statut' => ['sometimes', 'string'],
            'compteur_id' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Capteur::query()
            ->when($v['type_capteur'] ?? null, fn ($q, $t) => $q->where('type_capteur', $t))
            ->when($v['statut'] ?? null, fn ($q, $s) => $q->where('statut', $s))
            ->when($v['compteur_id'] ?? null, fn ($q, $c) => $q->where('compteur_id', $c))
            ->orderBy('id')->paginate($v['per_page'] ?? 25);

        return response()->json([
            'data' => CapteurResource::collection($page)->resolve(),
            'meta' => ['page' => $page->currentPage(), 'derniere_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** consulterAlertes() : toutes les alertes de la plateforme. */
    public function alertes(Request $request): JsonResponse
    {
        $page = Alerte::query()->with(['compteur.abonne.user', 'capteur:id,device_id'])
            ->when($request->string('statut')->toString(), fn ($q, $s) => $q->where('statut', $s))
            ->when($request->string('niveau')->toString(), fn ($q, $n) => $q->where('niveau', $n))
            ->latest('date_heure')->paginate(25);

        return response()->json([
            'data' => AlerteResource::collection($page)->resolve(),
            'meta' => ['page' => $page->currentPage(), 'derniere_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
