<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('search_performance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('provider', 24);
            $table->date('measured_on');
            $table->string('query', 500)->nullable();
            $table->string('page', 1000)->nullable();
            $table->string('device', 32)->nullable();
            $table->string('country', 16)->nullable();
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('ctr', 10, 6)->default(0);
            $table->decimal('position', 10, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['provider', 'measured_on']);
            $table->index(['application_id', 'measured_on']);
            $table->index(['query', 'measured_on']);
        });

        Schema::create('discovery_experiments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('key', 120)->unique();
            $table->string('name', 180);
            $table->string('surface', 160);
            $table->string('status', 24)->default('draft');
            $table->string('goal_event', 48)->default('conversion');
            $table->unsignedTinyInteger('allocation_percent')->default(100);
            $table->json('variants');
            $table->json('metadata')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['surface', 'status']);
            $table->index(['application_id', 'status']);
        });

        Schema::create('discovery_experiment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_id')->constrained('discovery_experiments')->cascadeOnDelete();
            $table->string('variant_key', 80);
            $table->string('session_id', 100);
            $table->string('event_type', 24);
            $table->decimal('conversion_value', 14, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['experiment_id', 'event_type', 'occurred_at'], 'experiment_event_lookup');
            $table->index(['session_id', 'occurred_at']);
        });

        Schema::create('experience_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('session_id', 100)->nullable();
            $table->string('path', 1000);
            $table->unsignedTinyInteger('score');
            $table->string('device_class', 32)->nullable();
            $table->json('issues')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('audited_at');
            $table->timestamps();
            $table->index(['path', 'audited_at']);
            $table->index(['application_id', 'audited_at']);
        });

        Schema::create('public_page_checks', function (Blueprint $table) {
            $table->id();
            $table->string('path', 1000);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('ok')->default(false);
            $table->boolean('canonical_ok')->default(false);
            $table->boolean('schema_ok')->default(false);
            $table->unsignedSmallInteger('image_errors')->default(0);
            $table->unsignedInteger('response_ms')->nullable();
            $table->json('issues')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index(['ok', 'checked_at']);
            $table->index(['checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_page_checks');
        Schema::dropIfExists('experience_audits');
        Schema::dropIfExists('discovery_experiment_events');
        Schema::dropIfExists('discovery_experiments');
        Schema::dropIfExists('search_performance_records');
    }
};
