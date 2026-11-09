<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Restreint une route à un ou plusieurs profils : ->middleware('role:administrateur'). */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$profils): Response
    {
        $profil = $request->user()?->profil?->value;

        if ($profil === null || ! in_array($profil, $profils, true)) {
            return response()->json(['message' => 'Accès refusé pour votre profil.'], 403);
        }

        return $next($request);
    }
}
