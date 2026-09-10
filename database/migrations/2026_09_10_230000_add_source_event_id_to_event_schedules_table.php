<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('source_event_id')
                ->nullable()
                ->after('production_id')
                ->index('event_schedules_source_event_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('event_schedules', function (Blueprint $table) {
            $table->dropIndex('event_schedules_source_event_id_index');
            $table->dropColumn('source_event_id');
        });
    }
};
