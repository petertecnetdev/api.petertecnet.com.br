<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table) {
            $table->timestamp('recovery_started_at')->nullable()->after('cancelled_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table) {
            $table->dropIndex(['recovery_started_at']);
            $table->dropColumn('recovery_started_at');
        });
    }
};
