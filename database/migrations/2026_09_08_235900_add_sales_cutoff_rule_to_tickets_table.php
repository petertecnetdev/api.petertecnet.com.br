<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('sales_cutoff_mode', 32)->nullable()->after('limit_date');
            $table->unsignedInteger('sales_cutoff_offset_minutes')->nullable()->after('sales_cutoff_mode');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['sales_cutoff_mode', 'sales_cutoff_offset_minutes']);
        });
    }
};
