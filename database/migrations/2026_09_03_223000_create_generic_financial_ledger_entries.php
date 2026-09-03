<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('app_slug', 80)->index();
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('payment_id')->nullable()->constrained('ecosystem_payments')->nullOnDelete();
            $table->string('type', 50);
            $table->string('status', 30)->default('posted');
            $table->char('currency', 3)->default('BRL');
            $table->decimal('amount', 14, 2);
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'source_type', 'source_id', 'type'], 'financial_ledger_payment_source_type_unique');
            $table->index(['app_slug', 'source_type', 'source_id', 'status'], 'financial_ledger_source_status_index');
            $table->index(['source_type', 'source_id', 'available_at'], 'financial_ledger_source_available_index');
        });

        if (Schema::hasColumn('applications', 'capabilities')) {
            $row = DB::table('applications')->where('slug', 'locaio')->first();
            if ($row) {
                $capabilities = $row->capabilities ? json_decode($row->capabilities, true) : [];
                $capabilities = array_values(array_unique(array_merge((array) $capabilities, ['leasing', 'payments', 'payouts', 'notifications', 'locations'])));
                DB::table('applications')->where('id', $row->id)->update([
                    'capabilities' => json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_ledger_entries');
    }
};
