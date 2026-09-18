<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            if (! Schema::hasColumn('artists', 'origin_type')) {
                $table->string('origin_type', 40)->nullable()->index();
            }
            if (! Schema::hasColumn('artists', 'origin_id')) {
                $table->unsignedBigInteger('origin_id')->nullable()->index();
            }
            if (! Schema::hasColumn('artists', 'origin_label')) {
                $table->string('origin_label', 190)->nullable();
            }
            if (! Schema::hasColumn('artists', 'reference_visible')) {
                $table->boolean('reference_visible')->default(true)->index();
            }
            if (! Schema::hasColumn('artists', 'onboarding_completed_at')) {
                $table->timestamp('onboarding_completed_at')->nullable();
            }
        });

        if (! Schema::hasTable('artist_relationships')) {
            Schema::create('artist_relationships', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('artist_id')->index();
                $table->string('related_type', 40)->index();
                $table->unsignedBigInteger('related_id')->index();
                $table->string('relationship_type', 60)->index();
                $table->unsignedBigInteger('first_event_id')->nullable()->index();
                $table->boolean('is_public')->default(true);
                $table->string('status', 30)->default('active')->index();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(
                    ['app_id', 'artist_id', 'related_type', 'related_id', 'relationship_type'],
                    'artist_relationship_unique'
                );
            });
        }

        if (! Schema::hasTable('artist_identity_claims')) {
            Schema::create('artist_identity_claims', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('artist_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable()->index();
                $table->string('status', 30)->default('pending')->index();
                $table->text('evidence_text')->nullable();
                $table->string('evidence_url', 2048)->nullable();
                $table->text('review_notes')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['app_id', 'artist_id', 'user_id'],
                    'artist_identity_claim_unique'
                );
                $table->index(['app_id', 'status', 'created_at'], 'artist_identity_claim_queue');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_identity_claims');
        Schema::dropIfExists('artist_relationships');

        Schema::table('artists', function (Blueprint $table) {
            $columns = collect([
                'origin_type',
                'origin_id',
                'origin_label',
                'reference_visible',
                'onboarding_completed_at',
            ])->filter(fn ($column) => Schema::hasColumn('artists', $column))->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
