<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moyens_paiement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('abonne_id')->constrained('abonnes')->cascadeOnDelete();
            $table->string('type', 20);                 // orange_money | wave | free_money | carte
            $table->string('libelle')->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('derniers_chiffres', 4)->nullable(); // jamais de PAN complet en base
            $table->string('expiration', 7)->nullable();         // MM/AA
            $table->string('token_prestataire')->nullable();     // jeton fourni par la passerelle
            $table->boolean('par_defaut')->default(false);
            $table->timestamps();
            $table->index('abonne_id');
        });

        // Opérations de facturation : recharges Woyofal
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();            // référence interne SC-XXXXXXXXXXXX
            $table->foreignId('abonne_id')->constrained('abonnes')->cascadeOnDelete();
            $table->foreignId('compteur_id')->constrained()->cascadeOnDelete();
            $table->foreignId('moyen_paiement_id')->nullable()->constrained('moyens_paiement')->nullOnDelete();
            $table->string('type', 20)->default('recharge');
            $table->decimal('montant_fcfa', 12, 2);               // crédit acheté
            $table->decimal('frais_fcfa', 12, 2)->default(0);     // frais du prestataire
            $table->double('kwh_estimes')->nullable();
            $table->string('moyen', 20);                          // orange_money | wave | free_money | carte
            $table->string('telephone', 30)->nullable();
            $table->string('statut', 20)->default('en_attente');  // en_attente | reussi | echoue
            $table->string('reference_prestataire')->nullable();
            $table->string('code_recharge', 40)->nullable();      // code Woyofal à saisir sur le compteur
            $table->string('message_erreur')->nullable();
            $table->timestamp('paye_le')->nullable();
            $table->timestamps();

            $table->index(['abonne_id', 'created_at']);
            $table->index(['compteur_id', 'statut', 'paye_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
        Schema::dropIfExists('moyens_paiement');
    }
};
