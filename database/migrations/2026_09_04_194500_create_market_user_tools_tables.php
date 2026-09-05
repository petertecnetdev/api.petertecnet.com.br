<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('market_positions')) {
            Schema::create('market_positions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('asset_id', 80);
                $table->string('symbol', 20);
                $table->decimal('quantity', 36, 18);
                $table->decimal('average_price', 24, 8);
                $table->char('currency', 3)->default('BRL');
                $table->string('label', 120)->nullable();
                $table->timestamps();

                $table->index(['app_id', 'user_id'], 'market_positions_app_user_idx');
                $table->index(['app_id', 'asset_id'], 'market_positions_app_asset_idx');
            });
        }

        if (! Schema::hasTable('market_risk_profiles')) {
            Schema::create('market_risk_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('risk_profile', 20)->default('moderado');
                $table->decimal('max_asset_exposure', 5, 2)->default(25);
                $table->decimal('max_scenario_loss', 5, 2)->default(8);
                $table->decimal('min_liquidity_reserve', 5, 2)->default(15);
                $table->timestamps();

                $table->unique(['app_id', 'user_id'], 'market_risk_profiles_app_user_unique');
            });
        }

        if (! Schema::hasTable('market_alerts')) {
            Schema::create('market_alerts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('asset_id', 80);
                $table->string('symbol', 20);
                $table->string('metric', 40);
                $table->string('operator', 4);
                $table->decimal('threshold', 30, 8);
                $table->boolean('active')->default(true);
                $table->timestamp('last_triggered_at')->nullable();
                $table->timestamps();

                $table->index(['app_id', 'user_id', 'active'], 'market_alerts_app_user_active_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('market_alerts');
        Schema::dropIfExists('market_risk_profiles');
        Schema::dropIfExists('market_positions');
    }
};
