<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('media_assets')) {
            Schema::create('media_assets', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('application_id');
                $table->unsignedBigInteger('owner_user_id')->nullable();
                $table->string('name', 255);
                $table->string('title', 255)->nullable();
                $table->text('description')->nullable();
                $table->string('alt_text', 500)->nullable();
                $table->string('kind', 32);
                $table->string('category', 80)->default('general');
                $table->string('purpose', 80)->default('general');
                $table->string('mime_type', 120)->nullable();
                $table->string('extension', 24)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->string('storage_disk', 80)->nullable();
                $table->string('storage_path', 1024)->nullable();
                $table->string('public_url', 2048)->nullable();
                $table->char('checksum', 64)->nullable();
                $table->string('visibility', 24)->default('private');
                $table->string('status', 24)->default('ready');
                $table->boolean('is_official')->default(false);
                $table->boolean('is_marketing_approved')->default(false);
                $table->boolean('is_ai_generated')->default(false);
                $table->json('metadata')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['application_id', 'status', 'visibility'], 'media_assets_scope_idx');
                $table->index(['application_id', 'category', 'purpose'], 'media_assets_taxonomy_idx');
                $table->index(['application_id', 'is_marketing_approved', 'is_official'], 'media_assets_marketing_idx');
                $table->index(['application_id', 'checksum'], 'media_assets_checksum_idx');
            });
        }

        if (! Schema::hasTable('media_variants')) {
            Schema::create('media_variants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('media_asset_id');
                $table->string('name', 80);
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->string('storage_disk', 80)->nullable();
                $table->string('storage_path', 1024)->nullable();
                $table->string('public_url', 2048)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['media_asset_id', 'name'], 'media_variant_name_unique');
            });
        }

        if (! Schema::hasTable('media_relations')) {
            Schema::create('media_relations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('media_asset_id');
                $table->unsignedBigInteger('application_id');
                $table->string('entity_type', 120);
                $table->string('entity_id', 191);
                $table->string('role', 80)->default('media');
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['media_asset_id', 'entity_type', 'entity_id', 'role'], 'media_relation_unique');
                $table->index(['application_id', 'entity_type', 'entity_id', 'role'], 'media_relation_entity_idx');
            });
        }

        if (! Schema::hasTable('media_collections')) {
            Schema::create('media_collections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('application_id');
                $table->unsignedBigInteger('owner_user_id')->nullable();
                $table->string('name', 160);
                $table->string('slug', 180);
                $table->text('description')->nullable();
                $table->string('purpose', 80)->default('general');
                $table->string('visibility', 24)->default('private');
                $table->string('status', 24)->default('active');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['application_id', 'slug'], 'media_collection_slug_unique');
                $table->index(['application_id', 'status', 'purpose'], 'media_collection_scope_idx');
            });
        }

        if (! Schema::hasTable('media_collection_items')) {
            Schema::create('media_collection_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('media_collection_id');
                $table->unsignedBigInteger('media_asset_id');
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['media_collection_id', 'media_asset_id'], 'media_collection_asset_unique');
                $table->index(['media_collection_id', 'sort_order'], 'media_collection_order_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_collection_items');
        Schema::dropIfExists('media_collections');
        Schema::dropIfExists('media_relations');
        Schema::dropIfExists('media_variants');
        Schema::dropIfExists('media_assets');
    }
};
