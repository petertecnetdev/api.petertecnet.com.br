<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            if (! Schema::hasColumn('establishments', 'ordering_enabled')) {
                $table->boolean('ordering_enabled')->default(true)->after('is_published');
            }
            if (! Schema::hasColumn('establishments', 'accepting_orders')) {
                $table->boolean('accepting_orders')->default(true)->after('ordering_enabled');
            }
            if (! Schema::hasColumn('establishments', 'delivery_enabled')) {
                $table->boolean('delivery_enabled')->default(true)->after('accepting_orders');
            }
            if (! Schema::hasColumn('establishments', 'pickup_enabled')) {
                $table->boolean('pickup_enabled')->default(true)->after('delivery_enabled');
            }
            if (! Schema::hasColumn('establishments', 'dine_in_enabled')) {
                $table->boolean('dine_in_enabled')->default(false)->after('pickup_enabled');
            }
            if (! Schema::hasColumn('establishments', 'delivery_fee')) {
                $table->decimal('delivery_fee', 10, 2)->default(0)->after('dine_in_enabled');
            }
            if (! Schema::hasColumn('establishments', 'minimum_order')) {
                $table->decimal('minimum_order', 10, 2)->default(0)->after('delivery_fee');
            }
            if (! Schema::hasColumn('establishments', 'estimated_delivery_minutes')) {
                $table->unsignedSmallInteger('estimated_delivery_minutes')->nullable()->after('minimum_order');
            }
            if (! Schema::hasColumn('establishments', 'opening_hours')) {
                $table->json('opening_hours')->nullable()->after('estimated_delivery_minutes');
            }
            if (! Schema::hasColumn('establishments', 'payment_methods')) {
                $table->json('payment_methods')->nullable()->after('opening_hours');
            }
            if (! Schema::hasColumn('establishments', 'pix_key')) {
                $table->string('pix_key', 255)->nullable()->after('payment_methods');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'subtotal')) {
                $table->decimal('subtotal', 10, 2)->default(0)->after('payment_method');
            }
            if (! Schema::hasColumn('orders', 'delivery_fee')) {
                $table->decimal('delivery_fee', 10, 2)->default(0)->after('subtotal');
            }
            if (! Schema::hasColumn('orders', 'delivery_address')) {
                $table->text('delivery_address')->nullable()->after('delivery_fee');
            }
            if (! Schema::hasColumn('orders', 'payment_reference')) {
                $table->string('payment_reference', 255)->nullable()->after('payment_method');
            }
            if (! Schema::hasColumn('orders', 'status_updated_at')) {
                $table->timestamp('status_updated_at')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = ['subtotal', 'delivery_fee', 'delivery_address', 'payment_reference', 'status_updated_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('establishments', function (Blueprint $table) {
            $columns = [
                'ordering_enabled', 'accepting_orders', 'delivery_enabled', 'pickup_enabled',
                'dine_in_enabled', 'delivery_fee', 'minimum_order', 'estimated_delivery_minutes',
                'opening_hours', 'payment_methods', 'pix_key',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('establishments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
