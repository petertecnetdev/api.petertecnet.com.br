<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name', 180);
                $table->string('slug', 190)->unique();
                $table->string('type', 60)->default('organization');
                $table->string('document', 32)->nullable()->index();
                $table->string('status', 30)->default('active')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('organization_memberships')) {
            Schema::create('organization_memberships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('role', 60)->default('member');
                $table->json('scopes')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();
                $table->unique(['organization_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('application_accesses')) {
            Schema::create('application_accesses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
                $table->string('role', 60)->default('member');
                $table->json('scopes')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->timestamp('granted_at')->nullable();
                $table->timestamps();
                $table->index(['application_id', 'user_id']);
                $table->index(['application_id', 'organization_id']);
            });
        }

        if (! Schema::hasTable('api_projects')) {
            Schema::create('api_projects', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
                $table->string('name', 180);
                $table->string('environment', 20)->default('sandbox')->index();
                $table->string('status', 30)->default('active')->index();
                $table->unsignedInteger('requests_per_minute')->default(60);
                $table->unsignedBigInteger('monthly_request_quota')->default(100000);
                $table->json('allowed_scopes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('api_credentials')) {
            Schema::create('api_credentials', function (Blueprint $table) {
                $table->id();
                $table->foreignId('api_project_id')->constrained('api_projects')->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('key_prefix', 24)->index();
                $table->string('secret_hash', 255);
                $table->json('scopes')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('webhook_endpoints')) {
            Schema::create('webhook_endpoints', function (Blueprint $table) {
                $table->id();
                $table->foreignId('api_project_id')->constrained('api_projects')->cascadeOnDelete();
                $table->string('url', 2048);
                $table->string('secret_hash', 255);
                $table->json('events')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedInteger('failure_count')->default(0);
                $table->timestamp('last_success_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('webhook_deliveries')) {
            Schema::create('webhook_deliveries', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
                $table->string('event', 120)->index();
                $table->string('status', 30)->default('pending')->index();
                $table->json('payload');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->text('response_body')->nullable();
                $table->timestamp('next_attempt_at')->nullable()->index();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('api_usage_records')) {
            Schema::create('api_usage_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('api_project_id')->nullable()->constrained('api_projects')->nullOnDelete();
                $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->uuid('request_id')->nullable()->index();
                $table->string('method', 12);
                $table->string('route', 255)->nullable()->index();
                $table->unsignedSmallInteger('status_code')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->unsignedBigInteger('request_bytes')->default(0);
                $table->unsignedBigInteger('response_bytes')->default(0);
                $table->string('environment', 20)->default('production')->index();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('idempotency_keys')) {
            Schema::create('idempotency_keys', function (Blueprint $table) {
                $table->id();
                $table->foreignId('api_project_id')->nullable()->constrained('api_projects')->cascadeOnDelete();
                $table->foreignId('application_id')->nullable()->constrained('applications')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->string('key', 190);
                $table->string('request_fingerprint', 64);
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->longText('response_body')->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
                $table->unique(['application_id', 'api_project_id', 'user_id', 'key'], 'idempotency_context_key_unique');
            });
        }

        if (! Schema::hasTable('inventory_reservations')) {
            Schema::create('inventory_reservations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->nullableMorphs('tenant');
                $table->nullableMorphs('resource');
                $table->nullableMorphs('orderable');
                $table->unsignedInteger('quantity');
                $table->string('status', 30)->default('reserved')->index();
                $table->timestamp('expires_at')->index();
                $table->timestamp('released_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'inventory_reservations', 'idempotency_keys', 'api_usage_records', 'webhook_deliveries',
            'webhook_endpoints', 'api_credentials', 'api_projects', 'application_accesses',
            'organization_memberships', 'organizations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
