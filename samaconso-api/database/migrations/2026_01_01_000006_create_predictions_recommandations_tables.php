<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Classe « Prediction » : id, periode, datePrediction, consommationPrevue, coutPrevu, modele, precision
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compteur_id')->constrained()->cascadeOnDelete();
            $table->string('periode', 20);                  // 7_jours | 30_jours
            $table->timestamp('date_prediction');           // moment de génération
            $table->date('date_debut');
            $table->date('date_fin');
            $table->double('consommation_prevue');          // kWh
            $table->double('cout_prevu');                   // FCFA
            $table->string('modele', 60);                   // RandomForestRegressor | moyenne_mobile (secours)
            $table->double('precision')->nullable();        // 0..1 (1 - MAPE en validation croisée temporelle)
            $table->jsonb('details')->nullable();           // série journalière + intervalle de confiance
            $table->timestamps();

            $table->index(['compteur_id', 'periode', 'date_prediction']);
        });

        // Classe « Recommandation » : id, titre, contenu, dateGeneration, priorite
        Schema::create('recommandations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compteur_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prediction_id')->nullable()->constrained('predictions')->nullOnDelete();
            $table->string('code', 60);                     // identifiant de la règle (déduplication)
            $table->string('titre');
            $table->text('contenu');
            $table->string('priorite', 10)->default('basse'); // haute | moyenne | basse
            $table->double('impact_estime_fcfa')->default(0); // économie mensuelle estimée
            $table->timestamp('date_generation');
            $table->timestamps();

            $table->index(['compteur_id', 'priorite', 'impact_estime_fcfa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommandations');
        Schema::dropIfExists('predictions');
    }
};
