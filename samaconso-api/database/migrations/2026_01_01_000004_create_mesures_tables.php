<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Classe « Mesure » : id, dateHeure, tension, courant, puissance, energie, frequence
        Schema::create('mesures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capteur_id')->constrained()->cascadeOnDelete();
            $table->foreignId('compteur_id')->constrained()->cascadeOnDelete(); // dénormalisé pour les requêtes
            $table->foreignId('equipement_id')->nullable()->constrained('equipements')->nullOnDelete();
            $table->timestampTz('date_heure');
            $table->double('tension')->nullable();           // V
            $table->double('courant')->nullable();           // A
            $table->double('puissance')->nullable();         // W
            $table->double('energie')->nullable();           // kWh cumulés (compteur du capteur)
            $table->double('frequence')->nullable();         // Hz
            $table->double('facteur_puissance')->nullable(); // cos φ
            $table->timestamp('created_at')->useCurrent();

            // Déduplication : rejeu d'un tampon local ESP32 sans doublons.
            $table->unique(['capteur_id', 'date_heure']);
            $table->index(['compteur_id', 'date_heure']);
        });

        // Agrégat horaire : alimente graphiques, historiques, prédictions (évite de scanner les mesures brutes).
        Schema::create('consommations_horaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capteur_id')->constrained()->cascadeOnDelete();
            $table->foreignId('compteur_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipement_id')->nullable()->constrained('equipements')->nullOnDelete();
            $table->timestampTz('heure'); // début de l'heure
            $table->double('energie_kwh')->default(0);
            $table->double('puissance_moy')->nullable();
            $table->double('puissance_max')->nullable();
            $table->double('tension_moy')->nullable();
            $table->double('courant_moy')->nullable();
            $table->double('frequence_moy')->nullable();
            $table->unsignedInteger('nb_mesures')->default(0);

            $table->unique(['capteur_id', 'heure']);
            $table->index(['compteur_id', 'heure']);
            $table->index(['equipement_id', 'heure']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consommations_horaires');
        Schema::dropIfExists('mesures');
    }
};
