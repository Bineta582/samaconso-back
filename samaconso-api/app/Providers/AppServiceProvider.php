<?php

namespace App\Providers;

use App\Services\Notifications\HttpSmsGateway;
use App\Services\Notifications\LogSmsGateway;
use App\Services\Notifications\SmsGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\RechargeCodeProvider;
use App\Services\Payment\SimulatedGateway;
use App\Services\Payment\SimulatedRechargeCode;
use App\Services\TariffService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TariffService::class, fn () => new TariffService(config('senelec')));

        // Passerelles interchangeables : remplacer par une implémentation réelle en production.
        $this->app->bind(PaymentGateway::class, fn () => match (config('services.paiement.driver')) {
            'simulated' => new SimulatedGateway,
            default => app(config('services.paiement.driver')),
        });
        $this->app->bind(RechargeCodeProvider::class, SimulatedRechargeCode::class);

        $this->app->bind(SmsGateway::class, fn () => match (config('services.sms.driver')) {
            'http' => new HttpSmsGateway,
            default => new LogSmsGateway,
        });
    }

    public function boot(): void
    {
        // Anti-force brute sur la connexion : 5 essais / minute par couple email + IP.
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));

        // API authentifiée : 120 requêtes / minute / utilisateur (le dashboard rafraîchit le live toutes les 5 s).
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));

        // Capteurs HTTP (secours + resynchronisation de tampon).
        RateLimiter::for('device', fn (Request $r) => Limit::perMinute(600)->by((string) $r->header('X-Device-Id', $r->ip())));

        // Génération de prédictions à la demande (calcul coûteux).
        RateLimiter::for('predictions', fn (Request $r) => Limit::perHour(10)->by($r->user()?->id ?: $r->ip()));
    }
}
