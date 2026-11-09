<?php

namespace App\Http\Controllers;

use App\Models\Compteur;
use Carbon\Carbon;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Compteur auquel l'utilisateur connecté a accès (404 sinon — on ne révèle pas l'existence). */
    protected function compteur(Request $request, int|string $id): Compteur
    {
        return Compteur::query()->visibleTo($request->user())->with('abonne')->findOrFail($id);
    }

    /** Compteur choisi par ?compteur_id=, sinon le premier compteur de l'abonné. */
    protected function compteurParDefaut(Request $request): Compteur
    {
        $query = Compteur::query()->visibleTo($request->user())->with('abonne')->orderBy('id');

        return $request->filled('compteur_id')
            ? $query->findOrFail($request->integer('compteur_id'))
            : $query->firstOrFail();
    }

    protected function date(?string $valeur, ?Carbon $defaut = null): Carbon
    {
        return $valeur ? Carbon::parse($valeur) : ($defaut ?? now());
    }
}
