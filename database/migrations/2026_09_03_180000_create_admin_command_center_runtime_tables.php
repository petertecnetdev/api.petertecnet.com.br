<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_incidents')) {
            Schema::create('admin_incidents', function (Blueprint $table) {
                $table->id();
                $table->string('public_id', 40)->unique();
                $table->string('title', 180);
                $table->text('description')->nullable();
                $table->string('severity', 20)->default('warning')->index();
                $table->string('status', 24)->default('open')->index();
                $table->string('source', 80)->default('manual')->index();
                $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->text('resolution')->nullable();
                $table->json('context')->nullable();
                $table->timestamps();
                $table->index(['application_id', 'status', 'severity'], 'admin_incidents_app_status_severity');
            });
        }

        if (! Schema::hasTable('admin_runtime_heartbeats')) {
            Schema::create('admin_runtime_heartbeats', function (Blueprint $table) {
                $table->id();
                $table->string('service', 100)->unique();
                $table->string('status', 24)->default('healthy')->index();
                $table->timestamp('last_seen_at')->index();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('admin_service_probes')) {
            Schema::create('admin_service_probes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->string('status', 24)->index();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->string('error', 500)->nullable();
                $table->timestamp('checked_at')->index();
                $table->timestamps();
                $table->index(['application_id', 'checked_at'], 'admin_service_probes_app_checked');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_service_probes');
        Schema::dropIfExists('admin_runtime_heartbeats');
        Schema::dropIfExists('admin_incidents');
    }
};
