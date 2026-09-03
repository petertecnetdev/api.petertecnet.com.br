<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operational_issues')) {
            Schema::create('operational_issues', function (Blueprint $table) {
                $table->id();
                $table->string('public_id', 40)->unique();
                $table->string('fingerprint', 190)->unique();
                $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
                $table->string('category', 50)->default('operational')->index();
                $table->string('domain', 80)->default('platform')->index();
                $table->string('title', 220);
                $table->text('description')->nullable();
                $table->string('severity', 20)->default('warning')->index();
                $table->string('priority', 4)->default('P2')->index();
                $table->string('status', 24)->default('new')->index();
                $table->string('source', 80)->default('monitor')->index();
                $table->unsignedInteger('impact_score')->default(25);
                $table->unsignedBigInteger('occurrence_count')->default(1);
                $table->unsignedInteger('regression_count')->default(0);
                $table->unsignedInteger('users_affected_count')->default(0);
                $table->unsignedInteger('establishments_affected_count')->default(0);
                $table->unsignedInteger('applications_affected_count')->default(1);
                $table->string('latest_method', 12)->nullable();
                $table->string('latest_route', 500)->nullable();
                $table->unsignedSmallInteger('latest_http_status')->nullable();
                $table->string('latest_error_code', 120)->nullable();
                $table->text('latest_message')->nullable();
                $table->string('source_version', 80)->nullable();
                $table->string('source_commit', 80)->nullable();
                $table->timestamp('first_seen_at')->index();
                $table->timestamp('last_seen_at')->index();
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->json('context')->nullable();
                $table->json('repair_plan')->nullable();
                $table->timestamps();
                $table->index(['application_id', 'status', 'priority'], 'operational_issues_app_status_priority');
            });
        }

        if (! Schema::hasTable('operational_issue_occurrences')) {
            Schema::create('operational_issue_occurrences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('issue_id')->constrained('operational_issues')->cascadeOnDelete();
                $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
                $table->unsignedBigInteger('interaction_id')->nullable()->index();
                $table->string('request_id', 120)->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('user_email')->nullable();
                $table->string('method', 12)->nullable();
                $table->string('route', 500)->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('error_code', 120)->nullable();
                $table->text('message')->nullable();
                $table->json('context')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();
                $table->index(['issue_id', 'occurred_at'], 'operational_issue_occurrences_issue_time');
            });
        }

        if (! Schema::hasTable('operational_issue_history')) {
            Schema::create('operational_issue_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('issue_id')->constrained('operational_issues')->cascadeOnDelete();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->string('from_status', 24)->nullable();
                $table->string('to_status', 24);
                $table->string('note', 1000)->nullable();
                $table->timestamps();
                $table->index(['issue_id', 'created_at'], 'operational_issue_history_issue_time');
            });
        }

        if (! Schema::hasTable('operational_slos')) {
            Schema::create('operational_slos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->nullable()->constrained('applications')->cascadeOnDelete();
                $table->string('domain', 80)->default('platform');
                $table->decimal('availability_target', 6, 3)->default(99.000);
                $table->decimal('max_error_rate', 6, 3)->default(5.000);
                $table->unsignedInteger('p95_latency_ms')->default(1500);
                $table->unsignedInteger('window_minutes')->default(60);
                $table->boolean('enabled')->default(true)->index();
                $table->timestamps();
                $table->unique(['application_id', 'domain'], 'operational_slos_app_domain_unique');
            });
        }

        if (! Schema::hasTable('admin_metric_rollups')) {
            Schema::create('admin_metric_rollups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->timestamp('bucket_started_at');
                $table->string('period', 12)->default('hour');
                $table->unsignedInteger('probe_count')->default(0);
                $table->unsignedInteger('success_count')->default(0);
                $table->unsignedInteger('error_count')->default(0);
                $table->unsignedInteger('avg_latency_ms')->nullable();
                $table->unsignedInteger('p95_latency_ms')->nullable();
                $table->decimal('availability_percent', 6, 3)->nullable();
                $table->decimal('error_rate_percent', 6, 3)->nullable();
                $table->timestamps();
                $table->unique(['application_id', 'bucket_started_at', 'period'], 'admin_metric_rollups_unique');
            });
        }

        if (Schema::hasTable('admin_incidents') && ! Schema::hasColumn('admin_incidents', 'issue_id')) {
            Schema::table('admin_incidents', function (Blueprint $table) {
                $table->unsignedBigInteger('issue_id')->nullable()->after('application_id')->index();
            });
        }

        if (Schema::hasTable('operational_slos') && DB::table('operational_slos')->whereNull('application_id')->where('domain', 'platform')->doesntExist()) {
            DB::table('operational_slos')->insert([
                'application_id' => null,
                'domain' => 'platform',
                'availability_target' => 99.0,
                'max_error_rate' => 5.0,
                'p95_latency_ms' => 1500,
                'window_minutes' => 60,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('admin_incidents') && Schema::hasColumn('admin_incidents', 'issue_id')) {
            Schema::table('admin_incidents', function (Blueprint $table) {
                $table->dropColumn('issue_id');
            });
        }

        Schema::dropIfExists('admin_metric_rollups');
        Schema::dropIfExists('operational_issue_history');
        Schema::dropIfExists('operational_issue_occurrences');
        Schema::dropIfExists('operational_slos');
        Schema::dropIfExists('operational_issues');
    }
};