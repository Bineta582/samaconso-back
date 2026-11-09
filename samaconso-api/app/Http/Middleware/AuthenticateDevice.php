<?php

namespace App\Http\Middleware;

use App\Models\Capteur;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie un appareil : en-têtes X-Device-Id et X-Device-Key.
 * La clé est comparée en temps constant à son empreinte SHA-256 stockée en base.
 */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->header('X-Device-Id');
        $cle = (string) $request->header('X-Device-Key');

        $capteur = $id !== '' ? Capteur::query()->where('device_id', $id)->first() : null;

        if (! $capteur || ! $capteur->api_key_hash || $cle === ''
            || ! hash_equals($capteur->api_key_hash, Capteur::hacherCle($cle))
            || $capteur->statut === 'inactif') {
            return response()->json(['message' => 'Capteur non authentifié.'], 401);
        }

        $request->attributes->set('capteur', $capteur);

        return $next($request);
    }
}
