<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'establishment_name')) {
                $table->string('establishment_name')->nullable()->after('establishment_type');
            }
        });
    }

    public function down(): void
    {
        // Reconciliation migration: keep the compatibility column on rollback
        // because older and newer event flows may both depend on it.
    }
};
