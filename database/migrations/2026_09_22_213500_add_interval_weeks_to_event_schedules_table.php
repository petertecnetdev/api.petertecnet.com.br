<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_schedules', function (Blueprint $table) {
            $table->unsignedSmallInteger('interval_weeks')->default(1)->after('generation_weeks');
        });
    }

    public function down(): void
    {
        Schema::table('event_schedules', function (Blueprint $table) {
            $table->dropColumn('interval_weeks');
        });
    }
};
