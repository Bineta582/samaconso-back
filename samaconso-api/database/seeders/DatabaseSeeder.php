<?php

namespace Database\Seeders;

use App\Enums\Profil;
use App\Models\Administrateur;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Compte administrateur initial (à changer immédiatement en production).
        $motDePasse = env('SEED_ADMIN_PASSWORD', 'Admin#2026');
        $admin = User::firstOrCreate(['email' => env('SEED_ADMIN_EMAIL', 'admin@samaconso.sn')], [
            'nom' => 'Ndiaye',
            'prenom' => 'Moussa',
            'telephone' => '+221770000001',
            'password' => $motDePasse,
            'profil' => Profil::Administrateur,
            'statut' => 'actif',
        ]);
        Administrateur::firstOrCreate(['user_id' => $admin->id]);
        $this->command?->info("Administrateur : {$admin->email}");

        // Jeu de données de démonstration (reprend les maquettes) — jamais en production par défaut.
        if (! app()->environment('production') || filter_var(env('SEED_DEMO', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->call(DemoSeeder::class);
        }
    }
}
