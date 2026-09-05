<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_agenda_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('production_id')->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['app_id', 'production_id'], 'event_agenda_settings_app_production_unique');
        });

        Schema::create('event_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('production_id')->index();
            $table->string('title');
            $table->text('description');
            $table->string('category', 120)->nullable();
            $table->string('image')->nullable();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('venue')->nullable();
            $table->string('address', 500)->nullable();
            $table->text('google_maps_url')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('cep', 20)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('max_attendees')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->boolean('is_private')->default(false);
            $table->string('event_format', 20)->default('in_person');
            $table->text('online_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['app_id', 'production_id', 'is_active', 'day_of_week'], 'event_schedules_agenda_lookup');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->unsignedBigInteger('event_schedule_id')->nullable()->after('production_id')->index();
            $table->date('event_schedule_occurrence_date')->nullable()->after('event_schedule_id')->index();
            $table->unique(
                ['event_schedule_id', 'event_schedule_occurrence_date'],
                'events_schedule_occurrence_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique('events_schedule_occurrence_unique');
            $table->dropColumn(['event_schedule_id', 'event_schedule_occurrence_date']);
        });

        Schema::dropIfExists('event_schedules');
        Schema::dropIfExists('event_agenda_settings');
    }
};
