<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plan_code', 80);
            $table->string('source', 80)->nullable();
            $table->string('handoff_channel', 40)->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('idempotency_key', 191);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['application_id', 'user_id', 'idempotency_key'],
                'subscription_intents_app_user_idempotency_unique'
            );
            $table->index(['application_id', 'status']);
            $table->index(['application_id', 'plan_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_intents');
    }
};
