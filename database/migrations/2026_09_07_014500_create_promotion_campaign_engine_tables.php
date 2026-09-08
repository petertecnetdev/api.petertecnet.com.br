<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('promotion_campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('app_id')->nullable()->index();
            $table->string('app_slug', 80)->nullable()->index();
            $table->unsignedBigInteger('production_id')->index();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('created_by')->index();
            $table->string('type', 40)->index();
            $table->string('objective', 40)->default('engagement')->index();
            $table->string('status', 30)->default('draft')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('visibility', 30)->default('public');
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->json('configuration')->nullable();
            $table->json('reward')->nullable();
            $table->boolean('requires_authorization')->default(false)->index();
            $table->string('compliance_status', 30)->default('not_required')->index();
            $table->string('authorization_number', 120)->nullable();
            $table->json('authorization_metadata')->nullable();
            $table->json('sponsor_metadata')->nullable();
            $table->json('boost_metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['event_id', 'status', 'starts_at', 'ends_at'], 'promotion_campaign_event_status_idx');
        });

        Schema::create('promotion_campaign_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('votes_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'sort_order'], 'promotion_campaign_option_order_unique');
        });

        Schema::create('promotion_campaign_participations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('option_id')->nullable()->index();
            $table->string('action', 40)->default('participate')->index();
            $table->string('status', 30)->default('valid')->index();
            $table->string('source', 80)->nullable();
            $table->string('referral_code', 120)->nullable()->index();
            $table->string('idempotency_key', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'user_id', 'action'], 'promotion_campaign_participation_once');
            $table->unique(['campaign_id', 'idempotency_key'], 'promotion_campaign_idempotency_unique');
        });

        Schema::create('promotion_campaign_rewards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('kind', 40)->index();
            $table->string('code', 120)->nullable()->unique();
            $table->decimal('value', 12, 2)->nullable();
            $table->string('status', 30)->default('issued')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('redeemed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'user_id'], 'promotion_campaign_reward_once');
        });

        Schema::create('promotion_campaign_winners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('option_id')->nullable()->index();
            $table->unsignedInteger('position')->default(1);
            $table->string('method', 50);
            $table->string('evidence_hash', 128)->nullable();
            $table->timestamp('selected_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'position'], 'promotion_campaign_winner_position_unique');
        });

        Schema::create('promotion_campaign_attributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('touchpoint', 40)->index();
            $table->decimal('gmv', 12, 2)->default(0);
            $table->decimal('platform_revenue', 12, 2)->default(0);
            $table->decimal('producer_cost', 12, 2)->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->string('idempotency_key', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'idempotency_key'], 'promotion_campaign_attribution_idempotency_unique');
            $table->index(['campaign_id', 'touchpoint', 'created_at'], 'promotion_campaign_attribution_metric_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_campaign_attributions');
        Schema::dropIfExists('promotion_campaign_winners');
        Schema::dropIfExists('promotion_campaign_rewards');
        Schema::dropIfExists('promotion_campaign_participations');
        Schema::dropIfExists('promotion_campaign_options');
        Schema::dropIfExists('promotion_campaigns');
    }
};
