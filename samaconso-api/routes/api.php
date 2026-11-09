<?php

use App\Http\Controllers\Api\Admin\AbonneAdminController;
use App\Http\Controllers\Api\Admin\SupervisionController;
use App\Http\Controllers\Api\AlerteController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CapteurController;
use App\Http\Controllers\Api\CompteurController;
use App\Http\Controllers\Api\ConsommationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EquipementController;
use App\Http\Controllers\Api\FacturationController;
use App\Http\Controllers\Api\MoyenPaiementController;
use App\Http\Controllers\Api\PredictionController;
use App\Http\Controllers\Api\ProfilController;
use App\Http\Controllers\Api\SeuilAlerteController;
use App\Http\Controllers\Api\WebhookPaiementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API REST SamaConso — préfixe /api/v1 (cf. bootstrap/app.php)
|--------------------------------------------------------------------------
*/

Route::get('/ping', fn () => response()->json(['service' => 'samaconso-api', 'status' => 'ok', 'heure' => now()->toIso8601String()]));

// ---- Authentification (publique) ------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
});

// ---- Passerelles externes ---------------------------------------------------------------
Route::post('webhooks/paiements/{driver}', WebhookPaiementController::class);

// ---- Appareils (authentification par clé d'appareil) ------------------------------------
Route::prefix('device')->middleware(['device.auth', 'throttle:device'])->group(function () {
    Route::post('mesures', [DeviceController::class, 'store']);
    Route::post('mesures/batch', [DeviceController::class, 'lot']);
    Route::post('status', [DeviceController::class, 'statut']);
    Route::get('commandes', [DeviceController::class, 'commandes']);
    Route::post('commandes/{commande}/ack', [DeviceController::class, 'acquitterCommande'])->whereNumber('commande');
});

// ---- Utilisateurs authentifiés (JWT) ------------------------------------------------------
Route::middleware(['auth:api', 'throttle:api'])->group(function () {

    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
    });

    // Profil
    Route::put('profil', [ProfilController::class, 'update']);
    Route::put('profil/mot-de-passe', [ProfilController::class, 'changerMotDePasse']);
    Route::get('profil/notifications', [ProfilController::class, 'preferences']);
    Route::put('profil/notifications', [ProfilController::class, 'majPreferences']);

    // Écrans abonné
    Route::get('accueil', [DashboardController::class, 'accueil']);
    Route::get('dashboard', [DashboardController::class, 'dashboard']);

    // Compteurs et ressources rattachées
    Route::get('compteurs', [CompteurController::class, 'index']);
    Route::post('compteurs', [CompteurController::class, 'store']);
    Route::get('compteurs/{compteur}', [CompteurController::class, 'show'])->whereNumber('compteur');
    Route::put('compteurs/{compteur}', [CompteurController::class, 'update'])->whereNumber('compteur');

    Route::prefix('compteurs/{compteur}')->where(['compteur' => '[0-9]+'])->group(function () {
        // Consommation
        Route::get('live', [ConsommationController::class, 'live']);
        Route::get('consommation', [ConsommationController::class, 'serie']);
        Route::get('consommation/repartition', [ConsommationController::class, 'repartition']);
        Route::get('consommation/comparaison', [ConsommationController::class, 'comparaison']);
        Route::get('consommation/mensuel', [ConsommationController::class, 'mensuel']);
        Route::get('consommation/export', [ConsommationController::class, 'export']);
        Route::get('mesures', [ConsommationController::class, 'mesures']);

        // Équipements & capteurs
        Route::get('equipements', [EquipementController::class, 'index']);
        Route::post('equipements', [EquipementController::class, 'store']);
        Route::get('capteurs', [CapteurController::class, 'index']);

        // Prédictions & recommandations
        Route::get('predictions', [PredictionController::class, 'index']);
        Route::post('predictions', [PredictionController::class, 'generer'])->middleware('throttle:predictions');
        Route::get('recommandations', [PredictionController::class, 'recommandations']);

        // Facturation Woyofal
        Route::get('solde', [FacturationController::class, 'solde']);
        Route::post('recharges', [FacturationController::class, 'recharger']);
    });

    Route::get('equipements/{equipement}', [EquipementController::class, 'show'])->whereNumber('equipement');
    Route::put('equipements/{equipement}', [EquipementController::class, 'update'])->whereNumber('equipement');
    Route::delete('equipements/{equipement}', [EquipementController::class, 'destroy'])->whereNumber('equipement');
    Route::post('equipements/{equipement}/relais', [EquipementController::class, 'relais'])->whereNumber('equipement');
    Route::patch('capteurs/{capteur}/equipement', [CapteurController::class, 'associer'])->whereNumber('capteur');

    // Alertes
    Route::get('alertes', [AlerteController::class, 'index']);
    Route::post('alertes/lire-tout', [AlerteController::class, 'lireTout']);
    Route::get('alertes/{alerte}', [AlerteController::class, 'show'])->whereNumber('alerte');
    Route::post('alertes/{alerte}/lire', [AlerteController::class, 'lire'])->whereNumber('alerte');
    Route::get('seuils-alerte', [SeuilAlerteController::class, 'index']);
    Route::put('seuils-alerte', [SeuilAlerteController::class, 'update']);

    // Facturation
    Route::get('paiements', [FacturationController::class, 'index']);
    Route::get('paiements/{paiement}', [FacturationController::class, 'show'])->whereNumber('paiement');
    Route::get('paiements/{paiement}/recu', [FacturationController::class, 'recu'])->whereNumber('paiement');
    Route::get('moyens-paiement', [MoyenPaiementController::class, 'index']);
    Route::post('moyens-paiement', [MoyenPaiementController::class, 'store']);
    Route::patch('moyens-paiement/{moyen}/defaut', [MoyenPaiementController::class, 'defaut'])->whereNumber('moyen');
    Route::delete('moyens-paiement/{moyen}', [MoyenPaiementController::class, 'destroy'])->whereNumber('moyen');

    // ---- Administrateur ---------------------------------------------------------------
    Route::prefix('admin')->middleware('role:administrateur')->group(function () {
        Route::get('dashboard', [SupervisionController::class, 'dashboard']);
        Route::get('capteurs', [SupervisionController::class, 'capteurs']);
        Route::get('alertes', [SupervisionController::class, 'alertes']);

        Route::get('abonnes', [AbonneAdminController::class, 'index']);
        Route::get('abonnes/{user}', [AbonneAdminController::class, 'show'])->whereNumber('user');
        Route::put('abonnes/{user}', [AbonneAdminController::class, 'update'])->whereNumber('user');
        Route::delete('abonnes/{user}', [AbonneAdminController::class, 'destroy'])->whereNumber('user');

        Route::delete('compteurs/{compteur}', [CompteurController::class, 'destroy'])->whereNumber('compteur');
        Route::post('compteurs/{compteur}/capteurs', [CapteurController::class, 'store'])->whereNumber('compteur');
        Route::put('capteurs/{capteur}', [CapteurController::class, 'update'])->whereNumber('capteur');
        Route::delete('capteurs/{capteur}', [CapteurController::class, 'destroy'])->whereNumber('capteur');
        Route::post('capteurs/{capteur}/regenerer-cle', [CapteurController::class, 'regenererCle'])->whereNumber('capteur');
    });
});
