<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Classe « Abonne » : idAbonne, typeAbonnement
        Schema::create('abonnes', function (Blueprint $table) {
            $table->id(); // idAbonne
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type_abonnement', 30)->default('menage'); // menage | petite_entreprise
            $table->string('numero_contrat', 40)->nullable()->unique(); // identifiant lié au contrat SENELEC
            $table->timestamps();
        });

        // Classe « Administrateur » : idAdmin
        Schema::create('administrateurs', function (Blueprint $table) {
            $table->id(); // idAdmin
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('administrateurs');
        Schema::dropIfExists('abonnes');
    }
};
