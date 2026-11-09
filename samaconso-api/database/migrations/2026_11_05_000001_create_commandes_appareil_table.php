<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commandes_appareil', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capteur_id')->constrained('capteurs')->cascadeOnDelete();
            $table->foreignId('equipement_id')->nullable()->constrained('equipements')->nullOnDelete();
            $table->string('action', 10);
            $table->string('raison', 60)->default('manuel');
            $table->string('statut', 20)->default('en_attente');
            $table->timestamp('terminee_at')->nullable();
            $table->string('erreur', 500)->nullable();
            $table->timestamps();
            $table->index(['capteur_id', 'statut', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commandes_appareil');
    }
};
