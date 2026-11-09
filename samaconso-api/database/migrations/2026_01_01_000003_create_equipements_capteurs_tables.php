<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Classe « Equipement » : id, nom, type, emplacement, puissanceNominale, description
        Schema::create('equipements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compteur_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('type', 40)->default('autre'); // climatiseur, refrigerateur, four, chauffe_eau, eclairage, ...
            $table->string('emplacement')->nullable();
            $table->double('puissance_nominale')->default(0); // en watts
            $table->text('description')->nullable();
            $table->double('coupure_auto_w')->nullable();      // si défini : coupure par relais au-delà de cette puissance
            $table->timestamps();
            $table->index('compteur_id');
        });

        // Classe « Capteur » : id, deviceId, typeCapteur, emplacement, statut, dateInstallation
        Schema::create('capteurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compteur_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipement_id')->nullable()->unique()->constrained('equipements')->nullOnDelete(); // 0..1 <-> 0..1
            $table->string('device_id', 64)->unique();      // identifiant HTTP de l'appareil
            $table->string('type_capteur', 20);             // PZEM-004T | SCT-013 | RELAIS | ESP32
            $table->string('emplacement')->nullable();
            $table->string('statut', 20)->default('actif'); // actif | inactif | hors_ligne
            $table->date('date_installation')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('api_key_hash', 64)->nullable(); // SHA-256 de la clé API de l'appareil
            $table->timestamps();
            $table->index(['compteur_id', 'type_capteur']);
        });

        // Relais (capteur de type RELAIS) pilotant l'équipement : coupure automatique / manuelle.
        Schema::table('equipements', function (Blueprint $table) {
            $table->foreignId('relais_capteur_id')->nullable()->constrained('capteurs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('relais_capteur_id');
        });
        Schema::dropIfExists('capteurs');
        Schema::dropIfExists('equipements');
    }
};
