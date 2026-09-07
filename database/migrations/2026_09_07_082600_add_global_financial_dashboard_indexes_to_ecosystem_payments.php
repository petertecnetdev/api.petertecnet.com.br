<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ecosystem_payments')) {
            return;
        }

        Schema::table('ecosystem_payments', function (Blueprint $table): void {
            $table->index(['status', 'created_at'], 'ecosystem_payments_status_created_at_index');
            $table->index('created_at', 'ecosystem_payments_created_at_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ecosystem_payments')) {
            return;
        }

        Schema::table('ecosystem_payments', function (Blueprint $table): void {
            $table->dropIndex('ecosystem_payments_status_created_at_index');
            $table->dropIndex('ecosystem_payments_created_at_index');
        });
    }
};
