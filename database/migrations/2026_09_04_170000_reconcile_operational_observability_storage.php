<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->reconcileOperationalIssues();
        $this->reconcileOperationalIssueOccurrences();
    }

    private function reconcileOperationalIssues(): void
    {
        if (! Schema::hasTable('operational_issues')) {
            return;
        }

        $missing = [
            'public_id' => ! Schema::hasColumn('operational_issues', 'public_id'),
            'application_id' => ! Schema::hasColumn('operational_issues', 'application_id'),
            'description' => ! Schema::hasColumn('operational_issues', 'description'),
            'priority' => ! Schema::hasColumn('operational_issues', 'priority'),
            'source' => ! Schema::hasColumn('operational_issues', 'source'),
            'repair_plan' => ! Schema::hasColumn('operational_issues', 'repair_plan'),
        ];

        if (in_array(true, $missing, true)) {
            Schema::table('operational_issues', function (Blueprint $table) use ($missing) {
                if ($missing['public_id']) {
                    $table->string('public_id', 40)->nullable()->unique();
                }
                if ($missing['application_id']) {
                    $table->unsignedBigInteger('application_id')->nullable()->index();
                }
                if ($missing['description']) {
                    $table->text('description')->nullable();
                }
                if ($missing['priority']) {
                    $table->string('priority', 4)->default('P2')->index();
                }
                if ($missing['source']) {
                    $table->string('source', 80)->default('legacy')->index();
                }
                if ($missing['repair_plan']) {
                    $table->json('repair_plan')->nullable();
                }
            });
        }

        Schema::table('operational_issues', function (Blueprint $table) {
            $table->string('fingerprint', 190)->change();
        });

        if (Schema::hasColumn('operational_issues', 'latest_application_id')) {
            DB::table('operational_issues')
                ->whereNull('application_id')
                ->whereNotNull('latest_application_id')
                ->update(['application_id' => DB::raw('latest_application_id')]);
        }

        if (Schema::hasColumn('operational_issues', 'latest_message')) {
            DB::table('operational_issues')
                ->whereNull('description')
                ->whereNotNull('latest_message')
                ->update(['description' => DB::raw('latest_message')]);
        }

        DB::table('operational_issues')
            ->whereIn('severity', ['attention', 'suspicious'])
            ->update(['severity' => 'warning']);

        DB::table('operational_issues')
            ->where('severity', 'critical')
            ->where('priority', 'P2')
            ->update(['priority' => 'P1']);

        DB::table('operational_issues')
            ->whereNull('domain')
            ->update(['domain' => 'platform']);

        DB::table('operational_issues')
            ->select('id')
            ->whereNull('public_id')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('operational_issues')
                        ->where('id', $row->id)
                        ->update(['public_id' => 'OPS-LEGACY-' . str_pad((string) $row->id, 10, '0', STR_PAD_LEFT)]);
                }
            });
    }

    private function reconcileOperationalIssueOccurrences(): void
    {
        if (! Schema::hasTable('operational_issue_occurrences')) {
            return;
        }

        $hasLegacyIssueId = Schema::hasColumn('operational_issue_occurrences', 'operational_issue_id');
        $missing = [
            'issue_id' => ! Schema::hasColumn('operational_issue_occurrences', 'issue_id'),
            'method' => ! Schema::hasColumn('operational_issue_occurrences', 'method'),
            'route' => ! Schema::hasColumn('operational_issue_occurrences', 'route'),
            'error_code' => ! Schema::hasColumn('operational_issue_occurrences', 'error_code'),
            'message' => ! Schema::hasColumn('operational_issue_occurrences', 'message'),
            'context' => ! Schema::hasColumn('operational_issue_occurrences', 'context'),
            'user_email' => ! Schema::hasColumn('operational_issue_occurrences', 'user_email'),
            'updated_at' => ! Schema::hasColumn('operational_issue_occurrences', 'updated_at'),
        ];

        if (in_array(true, $missing, true)) {
            Schema::table('operational_issue_occurrences', function (Blueprint $table) use ($missing) {
                if ($missing['issue_id']) {
                    $table->unsignedBigInteger('issue_id')->nullable()->index();
                }
                if ($missing['method']) {
                    $table->string('method', 12)->nullable();
                }
                if ($missing['route']) {
                    $table->string('route', 500)->nullable();
                }
                if ($missing['error_code']) {
                    $table->string('error_code', 120)->nullable();
                }
                if ($missing['message']) {
                    $table->text('message')->nullable();
                }
                if ($missing['context']) {
                    $table->json('context')->nullable();
                }
                if ($missing['user_email']) {
                    $table->string('user_email')->nullable();
                }
                if ($missing['updated_at']) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        if ($hasLegacyIssueId) {
            DB::table('operational_issue_occurrences')
                ->whereNull('issue_id')
                ->update(['issue_id' => DB::raw('operational_issue_id')]);

            Schema::table('operational_issue_occurrences', function (Blueprint $table) {
                $table->unsignedBigInteger('operational_issue_id')->nullable()->change();
            });
        }

        if (Schema::hasColumn('operational_issue_occurrences', 'interaction_id')) {
            Schema::table('operational_issue_occurrences', function (Blueprint $table) {
                $table->unsignedBigInteger('interaction_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally irreversible. This migration reconciles production
        // schema drift while preserving legacy columns and historical data.
    }
};
