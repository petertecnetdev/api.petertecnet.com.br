<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecosystem_payments', function (Blueprint $table) {
            $table->timestamp('settled_at')->nullable()->after('available_at')->index();
            $table->string('settlement_status', 32)->default('unverified')->after('settled_at')->index();
            $table->string('settlement_reference', 255)->nullable()->after('settlement_status');
            $table->decimal('settlement_net_amount', 14, 2)->nullable()->after('settlement_reference');
        });

        Schema::create('provider_statement_reports', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->index();
            $table->string('report_type', 40)->default('released_money')->index();
            $table->string('window_key', 120);
            $table->string('provider_task_id', 120)->nullable()->index();
            $table->string('provider_report_id', 120)->nullable()->index();
            $table->string('file_name', 255)->nullable();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('imported_at')->nullable()->index();
            $table->string('error_message', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'report_type', 'window_key'], 'provider_statement_report_window_unique');
        });

        Schema::create('provider_statement_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('provider_statement_reports')->cascadeOnDelete();
            $table->string('provider', 40)->index();
            $table->string('provider_source_id', 255)->nullable()->index();
            $table->string('external_reference', 255)->nullable()->index();
            $table->string('record_type', 80)->nullable()->index();
            $table->string('description', 120)->nullable()->index();
            $table->char('currency', 3)->default('BRL');
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('net_credit_amount', 14, 2)->default(0);
            $table->decimal('net_debit_amount', 14, 2)->default(0);
            $table->decimal('provider_fee_amount', 14, 2)->default(0);
            $table->decimal('seller_amount', 14, 2)->nullable();
            $table->decimal('balance_amount', 14, 2)->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('bank_account_reference', 64)->nullable();
            $table->char('fingerprint', 64)->unique();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['provider', 'occurred_at'], 'provider_statement_entry_provider_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_statement_entries');
        Schema::dropIfExists('provider_statement_reports');

        Schema::table('ecosystem_payments', function (Blueprint $table) {
            $table->dropColumn(['settled_at', 'settlement_status', 'settlement_reference', 'settlement_net_amount']);
        });
    }
};
