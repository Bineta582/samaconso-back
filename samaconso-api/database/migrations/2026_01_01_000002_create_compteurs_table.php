<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Classe « Compteur » : id, numeroCompteur, typeCompteur, adresse, statut, dateInstallation
        Schema::create('compteurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('abonne_id')->constrained('abonnes')->cascadeOnDelete();
            $table->string('numero_compteur', 30)->unique();
            $table->string('type_compteur', 20)->default('woyofal'); // woyofal | postpaye
            $table->string('adresse')->nullable();
            $table->string('statut', 20)->default('actif'); // actif | maintenance | hors_ligne | inactif
            $table->date('date_installation')->nullable();

            // Suivi du crédit Woyofal (estimation côté plateforme)
            $table->decimal('solde_initial_fcfa', 12, 2)->default(0);
            $table->timestamp('solde_initial_at')->nullable();
            $table->decimal('objectif_mensuel_kwh', 8, 2)->nullable();

            $table->timestamps();
            $table->index(['abonne_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compteurs');
    }
};
