<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_worker_controls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->string('worker_key', 64);
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('interval_minutes')->default(60);
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'worker_key'], 'automation_worker_control_unique');
        });

        Schema::create('automation_worker_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->string('worker_key', 64);
            $table->string('status', 16)->default('queued');
            $table->json('result')->nullable();
            $table->string('last_error', 180)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['app_id', 'worker_key', 'created_at'], 'automation_worker_history');
            $table->index(['status', 'created_at'], 'automation_worker_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_worker_runs');
        Schema::dropIfExists('automation_worker_controls');
    }
};
