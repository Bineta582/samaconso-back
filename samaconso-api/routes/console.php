<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tâches planifiées (lancer `php artisan schedule:work` ou une entrée cron)
|--------------------------------------------------------------------------
*/

// Agrège les mesures brutes en consommations horaires (rapide pour les graphiques).
Schedule::command('mesures:agreger --heures=2')->everyFiveMinutes()->withoutOverlapping();

// Capteurs hors ligne, seuils journaliers (kWh / FCFA), crédit Woyofal bas.
Schedule::command('alertes:verifier')->everyFiveMinutes()->withoutOverlapping();

// Nouvelle analyse (Random Forest) chaque nuit pour tous les compteurs.
Schedule::command('predictions:generer')->dailyAt('02:00')->withoutOverlapping();

// Purge des mesures brutes anciennes (les agrégats horaires sont conservés).
Schedule::command('mesures:purger')->dailyAt('03:30');

// Bilan hebdomadaire par email (abonnés ayant activé l'option), le lundi matin.
Schedule::command('rapports:hebdomadaires')->weeklyOn(1, '07:00');
