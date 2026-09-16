<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_obligations', function (Blueprint $table) {
            $table->foreignId('source_financial_transaction_id')
                ->nullable()
                ->after('financial_account_id')
                ->constrained('financial_transactions')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->unique(
                ['application_id', 'source_financial_transaction_id', 'beneficiary_type', 'beneficiary_id'],
                'payout_source_beneficiary_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payout_obligations', function (Blueprint $table) {
            $table->dropUnique('payout_source_beneficiary_uq');
            $table->dropConstrainedForeignId('source_financial_transaction_id');
        });
    }
};
