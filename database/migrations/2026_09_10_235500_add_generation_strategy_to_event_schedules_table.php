<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_schedules', function (Blueprint $table) {
            $table->string('generation_mode', 20)->nullable()->after('online_url');
            $table->unsignedTinyInteger('generation_delay_days')->nullable()->after('generation_mode');
            $table->unsignedSmallInteger('generation_weeks')->nullable()->after('generation_delay_days');
        });
    }

    public function down(): void
    {
        Schema::table('event_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'generation_mode',
                'generation_delay_days',
                'generation_weeks',
            ]);
        });
    }
};
