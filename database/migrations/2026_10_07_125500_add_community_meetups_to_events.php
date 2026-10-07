<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                if (! Schema::hasColumn('events', 'kind')) {
                    $table->string('kind', 24)->default('commercial')->index()->after('production_id');
                }
                if (! Schema::hasColumn('events', 'created_by_user_id')) {
                    $table->foreignId('created_by_user_id')->nullable()->after('production_id')->constrained('users')->nullOnDelete();
                    $table->index(['app_id', 'created_by_user_id', 'start_date'], 'events_creator_schedule_idx');
                }
                if (! Schema::hasColumn('events', 'attendance_radius_m')) {
                    $table->unsignedInteger('attendance_radius_m')->default(250)->after('max_attendees');
                }
                if (! Schema::hasColumn('events', 'show_attendees')) {
                    $table->boolean('show_attendees')->default(true)->after('attendance_radius_m');
                }
            });

            if (Schema::hasColumn('events', 'kind')) {
                DB::table('events')->whereNull('kind')->orWhere('kind', '')->update(['kind' => 'commercial']);
            }
        }

        if (! Schema::hasTable('event_attendances')) {
            Schema::create('event_attendances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 24)->default('interested');
                $table->timestamp('checked_in_at')->nullable();
                $table->string('checkin_method', 24)->nullable();
                $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('distance_meters')->nullable();
                $table->timestamps();

                $table->unique(['app_id', 'event_id', 'user_id'], 'event_attendance_unique');
                $table->index(['app_id', 'event_id', 'status'], 'event_attendance_status_idx');
                $table->index(['app_id', 'user_id', 'status'], 'event_attendance_user_idx');
                $table->index(['event_id', 'checked_in_at'], 'event_attendance_checkin_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_attendances');

        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                if (Schema::hasColumn('events', 'show_attendees')) {
                    $table->dropColumn('show_attendees');
                }
                if (Schema::hasColumn('events', 'attendance_radius_m')) {
                    $table->dropColumn('attendance_radius_m');
                }
                if (Schema::hasColumn('events', 'created_by_user_id')) {
                    $table->dropForeign(['created_by_user_id']);
                    $table->dropIndex('events_creator_schedule_idx');
                    $table->dropColumn('created_by_user_id');
                }
                if (Schema::hasColumn('events', 'kind')) {
                    $table->dropColumn('kind');
                }
            });
        }
    }
};
