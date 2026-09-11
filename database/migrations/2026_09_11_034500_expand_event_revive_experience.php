<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_posts') && ! Schema::hasColumn('event_posts', 'file_id')) {
            Schema::table('event_posts', function (Blueprint $table) {
                $table->unsignedBigInteger('file_id')->nullable()->after('parent_id')->index();
            });
        }

        if (Schema::hasTable('event_ratings')) {
            Schema::table('event_ratings', function (Blueprint $table) {
                if (! Schema::hasColumn('event_ratings', 'comment')) {
                    $table->text('comment')->nullable()->after('rating');
                }
                if (! Schema::hasColumn('event_ratings', 'organization_rating')) {
                    $table->unsignedTinyInteger('organization_rating')->nullable()->after('comment');
                }
                if (! Schema::hasColumn('event_ratings', 'service_rating')) {
                    $table->unsignedTinyInteger('service_rating')->nullable()->after('organization_rating');
                }
                if (! Schema::hasColumn('event_ratings', 'music_rating')) {
                    $table->unsignedTinyInteger('music_rating')->nullable()->after('service_rating');
                }
                if (! Schema::hasColumn('event_ratings', 'value_rating')) {
                    $table->unsignedTinyInteger('value_rating')->nullable()->after('music_rating');
                }
                if (! Schema::hasColumn('event_ratings', 'producer_response')) {
                    $table->text('producer_response')->nullable()->after('verified_attendee');
                }
                if (! Schema::hasColumn('event_ratings', 'producer_responded_by')) {
                    $table->unsignedBigInteger('producer_responded_by')->nullable()->after('producer_response');
                }
                if (! Schema::hasColumn('event_ratings', 'producer_responded_at')) {
                    $table->timestamp('producer_responded_at')->nullable()->after('producer_responded_by');
                }
            });
        }

        if (! Schema::hasTable('event_rating_helpful')) {
            Schema::create('event_rating_helpful', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('rating_user_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();

                $table->unique(
                    ['app_id', 'event_id', 'rating_user_id', 'user_id'],
                    'evt_rating_helpful_unique'
                );
                $table->index(
                    ['app_id', 'event_id', 'rating_user_id'],
                    'evt_rating_helpful_scope_idx'
                );
            });
        }

        if (! Schema::hasTable('event_revive_preferences')) {
            Schema::create('event_revive_preferences', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('user_id');
                $table->boolean('show_attendance')->default(false);
                $table->boolean('notify_next')->default(true);
                $table->timestamps();

                $table->unique(['app_id', 'event_id', 'user_id'], 'evt_revive_pref_unique');
                $table->index(['app_id', 'event_id', 'show_attendance'], 'evt_revive_pref_scope_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_revive_preferences');
        Schema::dropIfExists('event_rating_helpful');

        if (Schema::hasTable('event_ratings')) {
            $columns = [
                'comment',
                'organization_rating',
                'service_rating',
                'music_rating',
                'value_rating',
                'producer_response',
                'producer_responded_by',
                'producer_responded_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('event_ratings', $column)) {
                    Schema::table('event_ratings', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }

        if (Schema::hasTable('event_posts') && Schema::hasColumn('event_posts', 'file_id')) {
            Schema::table('event_posts', function (Blueprint $table) {
                $table->dropColumn('file_id');
            });
        }
    }
};
