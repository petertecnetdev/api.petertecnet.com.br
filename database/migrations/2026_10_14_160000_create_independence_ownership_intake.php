<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Expand-only, intentionally no backfill: existing producers, events and sales
        // keep their legacy authorization semantics until an audited migration.
        foreach (['events', 'establishments'] as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (! Schema::hasColumn($name, 'ownership_status')) $table->string('ownership_status', 24)->nullable()->index();
                if (! Schema::hasColumn($name, 'ownership_verified_at')) $table->timestamp('ownership_verified_at')->nullable();
                if (! Schema::hasColumn($name, 'verification_status')) $table->string('verification_status', 32)->nullable();
                if (! Schema::hasColumn($name, 'listing_source')) $table->string('listing_source', 48)->nullable();
                if (! Schema::hasColumn($name, 'source_url')) $table->string('source_url', 2048)->nullable();
                if (! Schema::hasColumn($name, 'last_verified_at')) $table->timestamp('last_verified_at')->nullable();
                if (! Schema::hasColumn($name, 'published_by_user_id')) $table->unsignedBigInteger('published_by_user_id')->nullable()->index();
            });
        }

        if (! Schema::hasTable('independence_ownership_claims')) {
            Schema::create('independence_ownership_claims', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->string('entity_type', 24);
                $table->unsignedBigInteger('entity_id');
                $table->unsignedBigInteger('claimant_user_id')->index();
                $table->string('contact_name', 160);
                $table->string('contact_email', 254);
                $table->string('contact_phone', 32)->nullable();
                $table->text('message')->nullable();
                $table->string('status', 24)->default('pending');
                $table->string('active_claim_key', 64)->nullable()->unique();
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $table->text('review_note')->nullable();
                $table->timestamp('consented_at');
                $table->timestamp('contact_verified_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->index(['app_id', 'entity_type', 'entity_id', 'status'], 'ownership_claim_target_status');
                $table->index(['app_id', 'status', 'created_at'], 'ownership_claim_review_queue');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('independence_ownership_claims');
        // Column additions are intentionally retained on rollback. Earlier
        // deployments may already have defined some of these fields; dropping
        // them would destroy historical verification data and risk live sales.
    }
};
