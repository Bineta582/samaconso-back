<?php

use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\EnsureRole;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
            'device.auth' => AuthenticateDevice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // L'API répond toujours en JSON (le frontend Angular consomme uniquement du JSON).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        // Ne pas divulguer le nom des modèles internes dans les 404.
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Ressource introuvable.'], 404);
            }
        });
    })->create();
