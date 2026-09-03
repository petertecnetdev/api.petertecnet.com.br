<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('brand')->nullable()->index();
            $table->string('category', 120)->nullable()->index();
            $table->string('subcategory', 120)->nullable()->index();
            $table->text('description')->nullable();
            $table->string('canonical_key')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('variant_key', 64);
            $table->string('name')->nullable();
            $table->string('sku', 120)->nullable()->index();
            $table->string('gtin', 32)->nullable()->unique();
            $table->json('specifications')->nullable();
            $table->decimal('package_quantity', 12, 3)->nullable();
            $table->string('package_unit', 24)->nullable();
            $table->string('source', 40)->nullable();
            $table->decimal('source_confidence', 5, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'variant_key']);
            $table->index(['product_id', 'sku']);
        });

        Schema::create('product_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias')->index();
            $table->string('source', 40)->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'normalized_alias']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('id')->constrained('product_variants')->nullOnDelete();
            $table->string('sale_unit', 24)->nullable()->after('stock');
            $table->unsignedTinyInteger('quality_score')->default(0)->after('sale_unit');
            $table->string('quality_status', 24)->default('legacy')->after('quality_score');
            $table->string('data_source', 40)->nullable()->after('quality_status');
            $table->json('catalog_metadata')->nullable()->after('data_source');
            $table->index(['entity_name', 'entity_id', 'quality_status']);
        });

        Schema::create('catalog_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type', 32)->default('manual');
            $table->string('filename')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_rows')->default(0);
            $table->unsignedInteger('review_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('catalog_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_data');
            $table->json('normalized_data')->nullable();
            $table->foreignId('matched_product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->json('issues')->nullable();
            $table->timestamps();
            $table->unique(['catalog_import_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_rows');
        Schema::dropIfExists('catalog_imports');

        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['product_variant_id']);
            $table->dropIndex(['entity_name', 'entity_id', 'quality_status']);
            $table->dropColumn([
                'product_variant_id',
                'sale_unit',
                'quality_score',
                'quality_status',
                'data_source',
                'catalog_metadata',
            ]);
        });

        Schema::dropIfExists('product_aliases');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
