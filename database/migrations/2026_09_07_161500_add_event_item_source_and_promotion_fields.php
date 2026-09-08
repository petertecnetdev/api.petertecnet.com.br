<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_items')) {
            throw new RuntimeException('Cannot add event item source and promotion fields: generic event_items table is missing.');
        }

        if (! Schema::hasColumn('event_items', 'source_item_id')) {
            Schema::table('event_items', function (Blueprint $table) {
                $table->unsignedBigInteger('source_item_id')->nullable()->after('event_id')->index();
            });
        }

        if (! Schema::hasColumn('event_items', 'promotion_enabled')) {
            Schema::table('event_items', function (Blueprint $table) {
                $table->boolean('promotion_enabled')->default(false)->after('quantity');
            });
        }

        if (! Schema::hasColumn('event_items', 'promotion_price')) {
            Schema::table('event_items', function (Blueprint $table) {
                $table->decimal('promotion_price', 12, 2)->nullable()->after('promotion_enabled');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('event_items')) {
            return;
        }

        $columns = collect(['source_item_id', 'promotion_enabled', 'promotion_price'])
            ->filter(fn (string $column) => Schema::hasColumn('event_items', $column))
            ->values()
            ->all();

        if ($columns === []) {
            return;
        }

        Schema::table('event_items', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
