<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('client_id', 64)->unique();
            $table->string('environment', 16)->default('production')->index();
            $table->string('status', 16)->default('active')->index();
            $table->json('scopes');
            $table->json('allowed_origins')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('terms_accepted_at');
            $table->timestamp('privacy_acknowledged_at');
            $table->timestamps();

            $table->index(['user_id', 'environment', 'status']);
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('name', 80)->default('Default key');
            $table->string('key_prefix', 24)->index();
            $table->char('key_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->nullable()->constrained('api_clients')->nullOnDelete();
            $table->uuid('request_id')->nullable()->index();
            $table->string('method', 10);
            $table->string('path', 512);
            $table->unsignedSmallInteger('status')->index();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['api_client_id', 'created_at']);
            $table->index(['api_client_id', 'status', 'created_at']);
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->json('events');
            $table->text('secret');
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->uuid('event_id')->index();
            $table->string('event', 120)->index();
            $table->json('payload');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('api_clients');
    }
};
