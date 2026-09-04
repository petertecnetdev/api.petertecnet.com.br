<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_ownerships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->string('asset_type', 80);
            $table->unsignedBigInteger('asset_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 40)->default('owner');
            $table->decimal('share_percent', 7, 4)->default(100);
            $table->boolean('is_primary')->default(false);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('permissions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'asset_type', 'asset_id', 'user_id'], 'asset_ownership_unique');
            $table->index(['app_id', 'user_id', 'asset_type']);
            $table->index(['app_id', 'asset_type', 'asset_id']);
        });

        Schema::create('asset_financial_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->string('asset_type', 80);
            $table->unsignedBigInteger('asset_id');
            $table->foreignId('lease_id')->nullable()->constrained('leases')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 20)->default('expense');
            $table->string('category', 80)->default('other');
            $table->string('description', 190);
            $table->decimal('amount', 14, 2);
            $table->date('occurred_on');
            $table->date('due_on')->nullable();
            $table->string('status', 30)->default('paid');
            $table->timestamp('paid_at')->nullable();
            $table->boolean('recurring')->default(false);
            $table->unsignedSmallInteger('recurrence_months')->nullable();
            $table->string('document_reference', 190)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_id', 'asset_type', 'asset_id', 'occurred_on'], 'asset_financial_asset_date_idx');
            $table->index(['app_id', 'asset_type', 'asset_id', 'direction', 'status'], 'asset_financial_status_idx');
        });

        Schema::create('asset_spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->string('asset_type', 80);
            $table->unsignedBigInteger('asset_id');
            $table->foreignId('parent_id')->nullable()->constrained('asset_spaces')->nullOnDelete();
            $table->string('name', 120);
            $table->string('type', 60)->default('room');
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_id', 'asset_type', 'asset_id', 'sort_order'], 'asset_spaces_asset_idx');
        });

        Schema::create('asset_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->string('asset_type', 80);
            $table->unsignedBigInteger('asset_id');
            $table->foreignId('space_id')->nullable()->constrained('asset_spaces')->nullOnDelete();
            $table->string('name', 160);
            $table->string('category', 80)->default('other');
            $table->string('serial_number', 120)->nullable();
            $table->string('condition', 40)->default('good');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('acquisition_value', 14, 2)->nullable();
            $table->date('acquired_on')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_id', 'asset_type', 'asset_id', 'space_id'], 'asset_inventory_asset_idx');
        });

        Schema::create('asset_maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->string('asset_type', 80);
            $table->unsignedBigInteger('asset_id');
            $table->foreignId('space_id')->nullable()->constrained('asset_spaces')->nullOnDelete();
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('priority', 16)->default('normal');
            $table->unsignedSmallInteger('frequency_months')->default(12);
            $table->date('last_completed_on')->nullable();
            $table->date('next_due_on');
            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_id', 'asset_type', 'asset_id', 'next_due_on'], 'asset_maintenance_plan_due_idx');
        });

        Schema::create('asset_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->string('asset_type', 80);
            $table->unsignedBigInteger('asset_id');
            $table->string('tag', 80);
            $table->string('category', 60)->nullable();
            $table->string('color', 32)->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'asset_type', 'asset_id', 'tag'], 'asset_tag_unique');
            $table->index(['app_id', 'asset_type', 'tag']);
        });

        Schema::create('asset_access_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->string('asset_type', 80);
            $table->unsignedBigInteger('asset_id');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('grantee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('grantee_email', 190)->nullable();
            $table->string('role', 60)->default('viewer');
            $table->json('permissions')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->unsignedBigInteger('access_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'asset_type', 'asset_id', 'revoked_at'], 'asset_access_asset_idx');
            $table->index(['app_id', 'grantee_user_id']);
            $table->index(['app_id', 'grantee_email']);
        });

        Schema::create('asset_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->nullable()->constrained('applications')->cascadeOnDelete();
            $table->string('asset_type', 80)->nullable();
            $table->string('rule_key', 100);
            $table->string('name', 160);
            $table->string('category', 60)->default('operations');
            $table->string('severity', 20)->default('attention');
            $table->boolean('is_enabled')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'asset_type', 'rule_key'], 'asset_alert_rule_lookup_idx');
        });

        Schema::create('asset_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->string('asset_type', 80);
            $table->unsignedBigInteger('asset_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 100);
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['app_id', 'asset_type', 'asset_id', 'occurred_at'], 'asset_audit_asset_time_idx');
            $table->index(['app_id', 'actor_user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_audit_events');
        Schema::dropIfExists('asset_alert_rules');
        Schema::dropIfExists('asset_access_grants');
        Schema::dropIfExists('asset_tags');
        Schema::dropIfExists('asset_maintenance_plans');
        Schema::dropIfExists('asset_inventory_items');
        Schema::dropIfExists('asset_spaces');
        Schema::dropIfExists('asset_financial_entries');
        Schema::dropIfExists('asset_ownerships');
    }
};
