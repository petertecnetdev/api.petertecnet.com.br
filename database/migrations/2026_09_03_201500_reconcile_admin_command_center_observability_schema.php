<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->reconcileServiceProbes();
        $this->reconcileRuntimeHeartbeats();
    }

    private function reconcileServiceProbes(): void
    {
        if (! Schema::hasTable('admin_service_probes')) {
            return;
        }

        $missing = [
            'application_id' => ! Schema::hasColumn('admin_service_probes', 'application_id'),
            'status' => ! Schema::hasColumn('admin_service_probes', 'status'),
            'http_status' => ! Schema::hasColumn('admin_service_probes', 'http_status'),
            'latency_ms' => ! Schema::hasColumn('admin_service_probes', 'latency_ms'),
            'error' => ! Schema::hasColumn('admin_service_probes', 'error'),
            'checked_at' => ! Schema::hasColumn('admin_service_probes', 'checked_at'),
            'created_at' => ! Schema::hasColumn('admin_service_probes', 'created_at'),
            'updated_at' => ! Schema::hasColumn('admin_service_probes', 'updated_at'),
        ];

        if (! in_array(true, $missing, true)) {
            return;
        }

        Schema::table('admin_service_probes', function (Blueprint $table) use ($missing) {
            if ($missing['application_id']) $table->unsignedBigInteger('application_id')->nullable();
            if ($missing['status']) $table->string('status', 24)->default('unknown');
            if ($missing['http_status']) $table->unsignedSmallInteger('http_status')->nullable();
            if ($missing['latency_ms']) $table->unsignedInteger('latency_ms')->nullable();
            if ($missing['error']) $table->string('error', 500)->nullable();
            if ($missing['checked_at']) $table->timestamp('checked_at')->nullable();
            if ($missing['created_at']) $table->timestamp('created_at')->nullable();
            if ($missing['updated_at']) $table->timestamp('updated_at')->nullable();
        });
    }

    private function reconcileRuntimeHeartbeats(): void
    {
        if (! Schema::hasTable('admin_runtime_heartbeats')) {
            return;
        }

        $missing = [
            'service' => ! Schema::hasColumn('admin_runtime_heartbeats', 'service'),
            'status' => ! Schema::hasColumn('admin_runtime_heartbeats', 'status'),
            'last_seen_at' => ! Schema::hasColumn('admin_runtime_heartbeats', 'last_seen_at'),
            'latency_ms' => ! Schema::hasColumn('admin_runtime_heartbeats', 'latency_ms'),
            'meta' => ! Schema::hasColumn('admin_runtime_heartbeats', 'meta'),
            'created_at' => ! Schema::hasColumn('admin_runtime_heartbeats', 'created_at'),
            'updated_at' => ! Schema::hasColumn('admin_runtime_heartbeats', 'updated_at'),
        ];

        if (! in_array(true, $missing, true)) {
            return;
        }

        Schema::table('admin_runtime_heartbeats', function (Blueprint $table) use ($missing) {
            if ($missing['service']) $table->string('service', 100)->nullable();
            if ($missing['status']) $table->string('status', 24)->default('unknown');
            if ($missing['last_seen_at']) $table->timestamp('last_seen_at')->nullable();
            if ($missing['latency_ms']) $table->unsignedInteger('latency_ms')->nullable();
            if ($missing['meta']) $table->json('meta')->nullable();
            if ($missing['created_at']) $table->timestamp('created_at')->nullable();
            if ($missing['updated_at']) $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        // Deliberately irreversible: this migration only reconciles columns that
        // older production schemas may be missing. Dropping them on rollback
        // would recreate the incident it is designed to prevent.
    }
};
