<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entity-scoped grants never replace the curator's original author identity.
        // They must not imply access to linked events or commercial checkout.
        if (! Schema::hasTable('independence_entity_grants')) {
            Schema::create('independence_entity_grants', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->string('entity_type', 24);
                $table->unsignedBigInteger('entity_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('approved_claim_id');
                $table->unsignedBigInteger('approved_by_user_id');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->unique(['app_id', 'entity_type', 'entity_id'], 'independence_one_owner_per_entity');
                $table->index(['app_id', 'user_id', 'entity_type', 'revoked_at'], 'independence_grants_by_user');
            });
        }

        if (! Schema::hasTable('independence_claim_audits')) {
            Schema::create('independence_claim_audits', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('claim_id');
                $table->unsignedBigInteger('actor_user_id');
                $table->string('previous_status', 24);
                $table->string('decision', 24);
                $table->string('verification_method', 32)->nullable();
                $table->string('evidence_digest', 64)->nullable();
                $table->text('review_note');
                $table->timestamp('created_at');
                $table->index(['app_id', 'claim_id', 'created_at'], 'independence_audit_claim_history');
            });
        }

        foreach (['events', 'establishments'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'commerce_authorized_at')) {
                Schema::table($name, fn (Blueprint $table) => $table->timestamp('commerce_authorized_at')->nullable());
            }
        }
    }

    public function down(): void
    {
        // Safe rollback: retain ownership, evidence and commerce-denial markers
        // rather than revoking grants without an operator-reviewed rollback.
    }
};
