<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'short_description')) {
                $table->text('short_description')->nullable()->after('description');
            }
            if (! Schema::hasColumn('items', 'pricing_model')) {
                $table->string('pricing_model', 30)->default('fixed')->after('price');
            }
            if (! Schema::hasColumn('items', 'price_min')) {
                $table->decimal('price_min', 12, 2)->nullable()->after('pricing_model');
            }
            if (! Schema::hasColumn('items', 'price_max')) {
                $table->decimal('price_max', 12, 2)->nullable()->after('price_min');
            }
            if (! Schema::hasColumn('items', 'setup_price')) {
                $table->decimal('setup_price', 12, 2)->nullable()->after('price_max');
            }
            if (! Schema::hasColumn('items', 'recurring_price')) {
                $table->decimal('recurring_price', 12, 2)->nullable()->after('setup_price');
            }
            if (! Schema::hasColumn('items', 'billing_interval')) {
                $table->string('billing_interval', 24)->nullable()->after('recurring_price');
            }
            if (! Schema::hasColumn('items', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(100)->after('is_featured');
            }
            if (! Schema::hasColumn('items', 'is_quote_enabled')) {
                $table->boolean('is_quote_enabled')->default(true)->after('sort_order');
            }
            if (! Schema::hasColumn('items', 'is_checkout_enabled')) {
                $table->boolean('is_checkout_enabled')->default(true)->after('is_quote_enabled');
            }
            if (! Schema::hasColumn('items', 'editor_config')) {
                $table->longText('editor_config')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('items', 'catalog_profile')) {
                $table->longText('catalog_profile')->nullable()->after('editor_config');
            }
            if (! Schema::hasColumn('items', 'seo_title')) {
                $table->string('seo_title')->nullable()->after('catalog_profile');
            }
            if (! Schema::hasColumn('items', 'seo_description')) {
                $table->string('seo_description', 320)->nullable()->after('seo_title');
            }
            if (! Schema::hasColumn('items', 'canonical_url')) {
                $table->text('canonical_url')->nullable()->after('seo_description');
            }
            if (! Schema::hasColumn('items', 'og_image')) {
                $table->text('og_image')->nullable()->after('canonical_url');
            }
            if (! Schema::hasColumn('items', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('og_image');
            }
        });

        if (! Schema::hasTable('item_catalog_versions')) {
            Schema::create('item_catalog_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('item_id');
                $table->unsignedBigInteger('app_id')->nullable();
                $table->unsignedInteger('version');
                $table->longText('snapshot');
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->string('reason', 120)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['item_id', 'version'], 'item_catalog_versions_item_version_unique');
                $table->index(['app_id', 'created_at'], 'item_catalog_versions_app_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_catalog_versions');

        Schema::table('items', function (Blueprint $table) {
            $columns = [
                'short_description', 'pricing_model', 'price_min', 'price_max',
                'setup_price', 'recurring_price', 'billing_interval', 'sort_order',
                'is_quote_enabled', 'is_checkout_enabled', 'editor_config', 'catalog_profile',
                'seo_title', 'seo_description', 'canonical_url', 'og_image', 'archived_at',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
