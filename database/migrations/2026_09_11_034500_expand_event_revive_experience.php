<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $postsTable = $this->physicalTable('event_posts', 'cutinapp_event_posts');
        $ratingsTable = $this->physicalTable('event_ratings', 'cutinapp_event_ratings');

        if (Schema::hasTable($postsTable) && ! Schema::hasColumn($postsTable, 'file_id')) {
            Schema::table($postsTable, function (Blueprint $table) {
                $table->unsignedBigInteger('file_id')->nullable()->after('parent_id')->index();
            });
        }

        if (Schema::hasTable($ratingsTable)) {
            Schema::table($ratingsTable, function (Blueprint $table) use ($ratingsTable) {
                if (! Schema::hasColumn($ratingsTable, 'comment')) {
                    $table->text('comment')->nullable()->after('rating');
                }
                if (! Schema::hasColumn($ratingsTable, 'organization_rating')) {
                    $table->unsignedTinyInteger('organization_rating')->nullable()->after('comment');
                }
                if (! Schema::hasColumn($ratingsTable, 'service_rating')) {
                    $table->unsignedTinyInteger('service_rating')->nullable()->after('organization_rating');
                }
                if (! Schema::hasColumn($ratingsTable, 'music_rating')) {
                    $table->unsignedTinyInteger('music_rating')->nullable()->after('service_rating');
                }
                if (! Schema::hasColumn($ratingsTable, 'value_rating')) {
                    $table->unsignedTinyInteger('value_rating')->nullable()->after('music_rating');
                }
                if (! Schema::hasColumn($ratingsTable, 'producer_response')) {
                    $table->text('producer_response')->nullable()->after('verified_attendee');
                }
                if (! Schema::hasColumn($ratingsTable, 'producer_responded_by')) {
                    $table->unsignedBigInteger('producer_responded_by')->nullable()->after('producer_response');
                }
                if (! Schema::hasColumn($ratingsTable, 'producer_responded_at')) {
                    $table->timestamp('producer_responded_at')->nullable()->after('producer_responded_by');
                }
            });
        }

        $this->refreshCompatibilityView('event_posts', $postsTable);
        $this->refreshCompatibilityView('event_ratings', $ratingsTable);

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

        if (! Schema::hasTable('content_reports')) {
            Schema::create('content_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->string('entity_type', 64);
                $table->unsignedBigInteger('entity_id');
                $table->string('target_type', 32);
                $table->unsignedBigInteger('target_id');
                $table->unsignedBigInteger('user_id');
                $table->string('reason', 40);
                $table->text('details')->nullable();
                $table->string('status', 24)->default('open');
                $table->text('moderation_note')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['app_id', 'entity_type', 'entity_id', 'target_type', 'target_id', 'user_id'],
                    'content_report_unique'
                );
                $table->index(
                    ['app_id', 'entity_type', 'entity_id', 'status', 'created_at'],
                    'content_report_scope_idx'
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
        Schema::dropIfExists('content_reports');
        Schema::dropIfExists('event_rating_helpful');

        $postsTable = $this->physicalTable('event_posts', 'cutinapp_event_posts');
        $ratingsTable = $this->physicalTable('event_ratings', 'cutinapp_event_ratings');

        $this->dropCompatibilityView('event_posts', $postsTable);
        $this->dropCompatibilityView('event_ratings', $ratingsTable);

        if (Schema::hasTable($ratingsTable)) {
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
                if (Schema::hasColumn($ratingsTable, $column)) {
                    Schema::table($ratingsTable, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }

        if (Schema::hasTable($postsTable) && Schema::hasColumn($postsTable, 'file_id')) {
            Schema::table($postsTable, function (Blueprint $table) {
                $table->dropColumn('file_id');
            });
        }

        $this->refreshCompatibilityView('event_posts', $postsTable);
        $this->refreshCompatibilityView('event_ratings', $ratingsTable);
    }

    private function physicalTable(string $generic, string $legacy): string
    {
        if (Schema::hasTable($legacy)) {
            return $legacy;
        }

        return $generic;
    }

    private function dropCompatibilityView(string $generic, string $physical): void
    {
        if (DB::getDriverName() === 'sqlite' || $generic === $physical) {
            return;
        }

        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return;
        }

        $type = DB::table('information_schema.tables')
            ->where('table_schema', $database)
            ->where('table_name', $generic)
            ->value('table_type');

        if (strtoupper((string) $type) === 'VIEW') {
            DB::statement('DROP VIEW IF EXISTS '.$generic);
        }
    }

    private function refreshCompatibilityView(string $generic, string $physical): void
    {
        if (DB::getDriverName() === 'sqlite' || $generic === $physical || ! Schema::hasTable($physical)) {
            return;
        }

        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return;
        }

        $type = DB::table('information_schema.tables')
            ->where('table_schema', $database)
            ->where('table_name', $generic)
            ->value('table_type');

        if (strtoupper((string) $type) !== 'VIEW') {
            return;
        }

        DB::statement(sprintf(
            'CREATE OR REPLACE ALGORITHM=MERGE VIEW %s AS SELECT * FROM %s',
            $generic,
            $physical,
        ));
    }
};
