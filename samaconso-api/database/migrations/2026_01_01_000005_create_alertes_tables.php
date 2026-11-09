<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Classe « Alerte » : id, type, message, seuil, dateHeure, statut
        Schema::create('alertes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compteur_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capteur_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mesure_id')->nullable()->constrained('mesures')->nullOnDelete(); // Mesure 1 — 0..* Alerte
            $table->string('type', 40);
            $table->string('niveau', 10)->default('warning'); // info | warning | critique
            $table->text('message');
            $table->double('seuil')->nullable();
            $table->double('valeur')->nullable(); // valeur mesurée ayant déclenché l'alerte
            $table->timestamp('date_heure');
            $table->string('statut', 10)->default('non_lue'); // non_lue | lue
            $table->timestamp('lue_at')->nullable();
            $table->timestamps();

            $table->index(['compteur_id', 'statut', 'date_heure']);
            $table->index(['compteur_id', 'type', 'date_heure']);
        });

        // Seuils personnalisables par abonné (page « Seuils d'alerte »)
        Schema::create('seuils_alerte', function (Blueprint $table) {
            $table->id();
            $table->foreignId('abonne_id')->constrained('abonnes')->cascadeOnDelete();
            $table->foreignId('equipement_id')->nullable()->constrained('equipements')->cascadeOnDelete();
            $table->string('type', 40);
            $table->double('valeur');
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['abonne_id', 'type', 'equipement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seuils_alerte');
        Schema::dropIfExists('alertes');
    }
};
