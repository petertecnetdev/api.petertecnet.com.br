<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->string('asset_type', 80);
            $table->unsignedBigInteger('asset_id');
            $table->decimal('acquisition_value', 14, 2)->nullable();
            $table->decimal('market_value', 14, 2)->nullable();
            $table->date('acquired_on')->nullable();
            $table->date('insurance_expires_on')->nullable();
            $table->date('document_expires_on')->nullable();
            $table->string('registry_reference', 190)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'asset_type', 'asset_id'], 'asset_profile_unique');
            $table->index(['app_id', 'asset_type', 'market_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_profiles');
    }
};
