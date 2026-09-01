<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecosystem_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('app_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('app_slug', 80)->index();
            $table->string('provider', 40)->index();
            $table->string('provider_payment_id', 255)->nullable();
            $table->string('source_type', 80);
            $table->string('source_reference', 255)->index();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('production_id')->nullable()->constrained('productions')->nullOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained('establishments')->nullOnDelete();
            $table->char('currency', 3)->default('BRL');
            $table->string('method', 40)->nullable();
            $table->string('status', 40)->default('pending')->index();
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('platform_fee', 12, 2)->default(0);
            $table->decimal('provider_fee', 12, 2)->default(0);
            $table->decimal('seller_net', 12, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['app_slug', 'source_type', 'source_reference'], 'ecosystem_payments_source_unique');
            $table->unique(['provider', 'provider_payment_id'], 'ecosystem_payments_provider_unique');
            $table->index(['app_slug', 'status', 'created_at']);
            $table->index(['production_id', 'status']);
            $table->index(['establishment_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecosystem_payments');
    }
};
