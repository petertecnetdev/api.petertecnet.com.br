<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_effects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id');
            $table->char('dedupe_key', 64)->unique();
            $table->string('aggregate_type', 80);
            $table->string('aggregate_id', 191);
            $table->string('effect_key', 120);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'aggregate_type', 'aggregate_id'], 'delivery_effects_aggregate_idx');
            $table->index(['status', 'claimed_at'], 'delivery_effects_retry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_effects');
    }
};
