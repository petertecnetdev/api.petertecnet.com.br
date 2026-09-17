<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not fully transactional. If a previous deployment stopped
        // part-way through this migration, keep the tables already created and
        // continue creating only what is still missing.
        if (! Schema::hasTable('financial_accounts')) {
            Schema::create('financial_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('owner_type', 80);
                $table->unsignedBigInteger('owner_id');
                $table->string('currency', 3)->default('BRL');
                $table->string('status', 32)->default('active');
                $table->timestamps();

                $table->unique(['application_id', 'owner_type', 'owner_id', 'currency'], 'fin_accounts_owner_currency_uq');
                $table->index(['application_id', 'status'], 'fin_accounts_app_status_idx');
            });
        }

        if (! Schema::hasTable('financial_transactions')) {
            Schema::create('financial_transactions', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('type', 40); // payment, fee, refund, chargeback, settlement, payout, adjustment
                $table->string('status', 32)->default('pending');
                $table->string('currency', 3)->default('BRL');
                $table->string('provider', 40)->nullable();
                $table->string('provider_reference', 191)->nullable();
                $table->string('idempotency_key', 191);
                $table->string('source_type', 80)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->timestamps();

                $table->unique(['application_id', 'idempotency_key'], 'fin_tx_app_idempotency_uq');
                $table->unique(['application_id', 'provider', 'provider_reference'], 'fin_tx_provider_ref_uq');
                $table->index(['application_id', 'type', 'status'], 'fin_tx_app_type_status_idx');
                $table->index(['source_type', 'source_id'], 'fin_tx_source_idx');
            });
        }

        if (! Schema::hasTable('financial_ledger_entries')) {
            Schema::create('financial_ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('financial_transaction_id')->constrained('financial_transactions')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('financial_account_id')->constrained('financial_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('direction', 6); // debit | credit
                $table->unsignedBigInteger('amount_cents');
                $table->string('currency', 3)->default('BRL');
                $table->string('role', 40); // gross, platform_fee, provider_fee, receivable, refund, payout...
                $table->timestamps();

                $table->index(['application_id', 'financial_account_id', 'id'], 'fin_ledger_account_idx');
                $table->index(['application_id', 'financial_transaction_id'], 'fin_ledger_tx_idx');
            });
        }

        if (! Schema::hasTable('payment_attempts')) {
            Schema::create('payment_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('payable_type', 80);
                $table->unsignedBigInteger('payable_id');
                $table->string('provider', 40);
                $table->string('provider_reference', 191)->nullable();
                $table->string('idempotency_key', 191);
                $table->string('status', 32)->default('created');
                $table->unsignedBigInteger('amount_cents');
                $table->string('currency', 3)->default('BRL');
                $table->unsignedSmallInteger('attempt_number')->default(1);
                $table->text('failure_code')->nullable();
                $table->text('failure_message')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->unique(['application_id', 'idempotency_key'], 'pay_attempt_app_idempotency_uq');
                $table->index(['application_id', 'payable_type', 'payable_id'], 'pay_attempt_payable_idx');
                $table->index(['application_id', 'status', 'created_at'], 'pay_attempt_status_idx');
            });
        }

        if (! Schema::hasTable('payment_webhook_receipts')) {
            Schema::create('payment_webhook_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('provider', 40);
                $table->string('event_id', 191);
                $table->string('event_type', 80)->nullable();
                $table->char('payload_hash', 64);
                $table->json('payload');
                $table->string('status', 32)->default('received');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamp('next_retry_at')->nullable();
                $table->timestamps();

                $table->unique(['application_id', 'provider', 'event_id'], 'pay_webhook_event_uq');
                $table->index(['status', 'next_retry_at'], 'pay_webhook_retry_idx');
            });
        }

        if (! Schema::hasTable('payout_obligations')) {
            Schema::create('payout_obligations', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('financial_account_id')->constrained('financial_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('beneficiary_type', 80);
                $table->unsignedBigInteger('beneficiary_id');
                $table->unsignedBigInteger('amount_cents');
                $table->string('currency', 3)->default('BRL');
                $table->string('status', 32)->default('held'); // held, eligible, processing, paid, failed, reversed
                $table->string('hold_reason', 80)->nullable(); // e.g. payout_destination_missing
                $table->string('provider', 40)->nullable();
                $table->string('provider_reference', 191)->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('eligible_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('next_retry_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['application_id', 'beneficiary_type', 'beneficiary_id', 'status'], 'payout_beneficiary_status_idx');
                $table->index(['status', 'next_retry_at'], 'payout_retry_idx');
                $table->index(['application_id', 'hold_reason'], 'payout_hold_reason_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_obligations');
        Schema::dropIfExists('payment_webhook_receipts');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('financial_ledger_entries');
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('financial_accounts');
    }
};
