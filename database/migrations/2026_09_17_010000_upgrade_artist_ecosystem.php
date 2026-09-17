<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            if (! Schema::hasColumn('artists', 'created_by_user_id')) $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
            if (! Schema::hasColumn('artists', 'short_bio')) $table->string('short_bio', 500)->nullable();
            if (! Schema::hasColumn('artists', 'professional_email')) $table->string('professional_email')->nullable();
            if (! Schema::hasColumn('artists', 'professional_phone')) $table->string('professional_phone', 40)->nullable();
            if (! Schema::hasColumn('artists', 'verification_status')) $table->string('verification_status', 30)->default('unverified')->index();
            if (! Schema::hasColumn('artists', 'verified_at')) $table->timestamp('verified_at')->nullable();
            if (! Schema::hasColumn('artists', 'is_active')) $table->boolean('is_active')->default(true)->index();
            if (! Schema::hasColumn('artists', 'profile_completion')) $table->unsignedTinyInteger('profile_completion')->default(0);
            if (! Schema::hasColumn('artists', 'press_kit')) $table->json('press_kit')->nullable();
            if (! Schema::hasColumn('artists', 'technical_rider')) $table->json('technical_rider')->nullable();
            if (! Schema::hasColumn('artists', 'hospitality_rider')) $table->json('hospitality_rider')->nullable();
            if (! Schema::hasColumn('artists', 'settings')) $table->json('settings')->nullable();
            if (! Schema::hasColumn('artists', 'metadata')) $table->json('metadata')->nullable();
            if (! Schema::hasColumn('artists', 'deleted_at')) $table->softDeletes();
        });

        Schema::table('event_artist', function (Blueprint $table) {
            if (! Schema::hasColumn('event_artist', 'status')) $table->string('status', 30)->default('confirmed')->index();
            if (! Schema::hasColumn('event_artist', 'invited_by_user_id')) $table->unsignedBigInteger('invited_by_user_id')->nullable()->index();
            if (! Schema::hasColumn('event_artist', 'invited_at')) $table->timestamp('invited_at')->nullable();
            if (! Schema::hasColumn('event_artist', 'responded_at')) $table->timestamp('responded_at')->nullable();
            if (! Schema::hasColumn('event_artist', 'checked_in_at')) $table->timestamp('checked_in_at')->nullable();
            if (! Schema::hasColumn('event_artist', 'fee_cents')) $table->unsignedBigInteger('fee_cents')->nullable();
            if (! Schema::hasColumn('event_artist', 'payment_status')) $table->string('payment_status', 30)->nullable()->index();
            if (! Schema::hasColumn('event_artist', 'invite_token')) $table->string('invite_token', 64)->nullable()->unique();
            if (! Schema::hasColumn('event_artist', 'private_notes')) $table->text('private_notes')->nullable();
            if (! Schema::hasColumn('event_artist', 'metadata')) $table->json('metadata')->nullable();
        });

        if (! Schema::hasTable('artist_managers')) {
            Schema::create('artist_managers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('artist_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('role', 80)->default('manager');
                $table->json('permissions')->nullable();
                $table->timestamps();
                $table->unique(['app_id', 'artist_id', 'user_id'], 'artist_managers_unique');
            });
        }

        if (! Schema::hasTable('artist_favorites')) {
            Schema::create('artist_favorites', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('artist_id')->index();
                $table->timestamps();
                $table->unique(['app_id', 'user_id', 'artist_id'], 'artist_favorites_unique');
            });
        }

        if (! Schema::hasTable('artist_invitations')) {
            Schema::create('artist_invitations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('event_id')->index();
                $table->unsignedBigInteger('artist_id')->nullable()->index();
                $table->unsignedBigInteger('invited_user_id')->nullable()->index();
                $table->unsignedBigInteger('invited_by_user_id')->index();
                $table->string('identifier_type', 30)->nullable();
                $table->string('identifier_hash', 64)->nullable()->index();
                $table->string('identifier_hint', 160)->nullable();
                $table->string('status', 30)->default('pending')->index();
                $table->string('token', 64)->unique();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('responded_at')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();
                $table->index(['app_id', 'event_id', 'status'], 'artist_invitation_event_status');
            });
        }

        if (! Schema::hasTable('artist_documents')) {
            Schema::create('artist_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('artist_id')->index();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->unsignedBigInteger('uploaded_by_user_id')->index();
                $table->string('type', 80)->default('document')->index();
                $table->string('name');
                $table->string('path', 2048);
                $table->string('visibility', 30)->default('private');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('artist_audit_logs')) {
            Schema::create('artist_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('artist_id')->nullable()->index();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->unsignedBigInteger('actor_user_id')->nullable()->index();
                $table->string('action', 100)->index();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->string('ip_hash', 64)->nullable();
                $table->string('user_agent_hash', 64)->nullable();
                $table->timestamps();
                $table->index(['app_id', 'artist_id', 'created_at'], 'artist_audit_timeline');
            });
        }

        if (! Schema::hasTable('artist_analytics_events')) {
            Schema::create('artist_analytics_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('artist_id')->index();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('event_type', 80)->index();
                $table->string('source', 120)->nullable()->index();
                $table->string('session_hash', 64)->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->useCurrent()->index();
                $table->timestamps();
                $table->index(['app_id', 'artist_id', 'event_type', 'occurred_at'], 'artist_analytics_rollup');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_analytics_events');
        Schema::dropIfExists('artist_audit_logs');
        Schema::dropIfExists('artist_documents');
        Schema::dropIfExists('artist_invitations');
        Schema::dropIfExists('artist_favorites');
        Schema::dropIfExists('artist_managers');
    }
};
