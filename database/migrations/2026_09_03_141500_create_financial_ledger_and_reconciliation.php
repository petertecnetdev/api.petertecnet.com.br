<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecosystem_payments', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('failed_at')->index();
            $table->timestamp('available_at')->nullable()->after('expires_at')->index();
            $table->timestamp('reconciled_at')->nullable()->after('available_at');
            $table->string('reconciliation_status', 32)->default('unverified')->after('reconciled_at')->index();
            $table->string('reconciliation_message', 500)->nullable()->after('reconciliation_status');
        });

        Schema::create('financial_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('payment_id')->nullable()->constrained('ecosystem_payments')->nullOnDelete();
            $table->foreignId('app_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('app_slug', 80)->index();
            $table->foreignId('establishment_id')->nullable()->constrained('establishments')->nullOnDelete();
            $table->foreignId('production_id')->nullable()->constrained('productions')->nullOnDelete();
            $table->string('provider', 40)->nullable()->index();
            $table->string('provider_payment_id', 255)->nullable()->index();
            $table->string('event_type', 48)->index();
            $table->char('currency', 3)->default('BRL');
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('platform_amount', 14, 2)->default(0);
            $table->decimal('provider_amount', 14, 2)->default(0);
            $table->decimal('seller_amount', 14, 2)->default(0);
            $table->timestamp('occurred_at')->index();
            $table->string('source_type', 80)->nullable();
            $table->string('source_reference', 255)->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['app_slug', 'event_type', 'occurred_at'], 'financial_ledger_app_event_date');
            $table->index(['payment_id', 'occurred_at'], 'financial_ledger_payment_date');
        });

        Schema::create('payment_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('ecosystem_payments')->cascadeOnDelete();
            $table->string('provider', 40)->index();
            $table->string('provider_payment_id', 255)->nullable()->index();
            $table->string('local_status', 40);
            $table->string('remote_status', 40)->nullable();
            $table->decimal('local_amount', 14, 2)->default(0);
            $table->decimal('remote_amount', 14, 2)->nullable();
            $table->decimal('local_provider_fee', 14, 2)->default(0);
            $table->decimal('remote_provider_fee', 14, 2)->nullable();
            $table->boolean('matched')->default(false)->index();
            $table->string('discrepancy_code', 64)->nullable()->index();
            $table->json('details')->nullable();
            $table->timestamp('checked_at')->index();
            $table->timestamps();

            $table->index(['matched', 'checked_at'], 'payment_reconciliations_match_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliations');
        Schema::dropIfExists('financial_ledger_entries');

        Schema::table('ecosystem_payments', function (Blueprint $table) {
            $table->dropColumn([
                'expires_at',
                'available_at',
                'reconciled_at',
                'reconciliation_status',
                'reconciliation_message',
            ]);
        });
    }
};
