<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('search_queries')) {
            Schema::create('search_queries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('session_key', 64)->nullable()->index();
                $table->string('query', 160);
                $table->string('normalized_query', 160)->index();
                $table->char('query_hash', 64)->index();
                $table->string('search_type', 40)->default('all')->index();
                $table->string('city', 120)->nullable()->index();
                $table->string('uf', 2)->nullable()->index();
                $table->json('filters')->nullable();
                $table->unsignedSmallInteger('result_count')->default(0);
                $table->boolean('zero_result')->default(false)->index();
                $table->string('source', 40)->default('global')->index();
                $table->timestamp('created_at')->useCurrent()->index();

                $table->index(['app_id', 'normalized_query', 'created_at'], 'search_queries_app_term_created_idx');
                $table->index(['app_id', 'city', 'created_at'], 'search_queries_app_city_created_idx');
                $table->index(['app_id', 'zero_result', 'created_at'], 'search_queries_app_zero_created_idx');
            });
        }

        if (! Schema::hasTable('search_clicks')) {
            Schema::create('search_clicks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('search_query_id')->nullable()->index();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('target_type', 40)->index();
                $table->unsignedBigInteger('target_id')->index();
                $table->unsignedSmallInteger('position')->nullable();
                $table->boolean('is_sponsored')->default(false)->index();
                $table->string('conversion_type', 40)->nullable()->index();
                $table->timestamp('converted_at')->nullable()->index();
                $table->timestamp('created_at')->useCurrent()->index();

                $table->index(['app_id', 'target_type', 'target_id', 'created_at'], 'search_clicks_target_created_idx');
                $table->index(['app_id', 'conversion_type', 'created_at'], 'search_clicks_conversion_created_idx');
            });
        }

        if (! Schema::hasTable('search_recents')) {
            Schema::create('search_recents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('target_type', 40);
                $table->unsignedBigInteger('target_id');
                $table->string('title', 255);
                $table->string('subtitle', 500)->nullable();
                $table->string('image', 2048)->nullable();
                $table->string('url', 2048);
                $table->timestamp('searched_at')->useCurrent()->index();
                $table->timestamps();

                $table->unique(['app_id', 'user_id', 'target_type', 'target_id'], 'search_recents_user_target_unique');
            });
        }

        if (! Schema::hasTable('search_saved_queries')) {
            Schema::create('search_saved_queries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('label', 120)->nullable();
                $table->string('query', 160);
                $table->string('normalized_query', 160)->index();
                $table->json('filters')->nullable();
                $table->boolean('notifications_enabled')->default(false);
                $table->timestamp('last_notified_at')->nullable();
                $table->timestamps();

                $table->unique(['app_id', 'user_id', 'normalized_query'], 'search_saved_query_unique');
            });
        }

        if (! Schema::hasTable('search_campaigns')) {
            Schema::create('search_campaigns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('owner_user_id')->nullable()->index();
                $table->unsignedBigInteger('production_id')->nullable()->index();
                $table->string('target_type', 40)->index();
                $table->unsignedBigInteger('target_id')->index();
                $table->string('label', 160)->nullable();
                $table->json('keywords');
                $table->string('city', 120)->nullable()->index();
                $table->string('uf', 2)->nullable()->index();
                $table->unsignedInteger('priority')->default(0)->index();
                $table->unsignedInteger('bid_cents')->default(0);
                $table->unsignedInteger('budget_cents')->nullable();
                $table->unsignedInteger('spent_cents')->default(0);
                $table->unsignedBigInteger('impressions')->default(0);
                $table->unsignedBigInteger('clicks')->default(0);
                $table->string('status', 30)->default('draft')->index();
                $table->timestamp('starts_at')->nullable()->index();
                $table->timestamp('ends_at')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['app_id', 'status', 'starts_at', 'ends_at'], 'search_campaigns_active_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_campaigns');
        Schema::dropIfExists('search_saved_queries');
        Schema::dropIfExists('search_recents');
        Schema::dropIfExists('search_clicks');
        Schema::dropIfExists('search_queries');
    }
};
