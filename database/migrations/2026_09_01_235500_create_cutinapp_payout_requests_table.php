<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutinapp_payout_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_id')->constrained('productions')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 80)->unique();
            $table->string('provider', 40)->default('mercadopago');
            $table->string('settlement_mode', 40)->default('platform_collection');
            $table->decimal('amount', 12, 2);
            $table->string('status', 30)->default('pending');
            $table->string('provider_transfer_id', 255)->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['production_id', 'status']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cutinapp_payout_requests');
    }
};
