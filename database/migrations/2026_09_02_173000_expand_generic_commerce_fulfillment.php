<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'public_id')) {
                $table->uuid('public_id')->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('orders', 'fulfillment_status')) {
                $table->string('fulfillment_status', 40)->nullable()->after('fulfillment');
            }
            if (! Schema::hasColumn('orders', 'fulfilled_at')) {
                $table->timestamp('fulfilled_at')->nullable()->after('attended_at');
            }
            if (! Schema::hasColumn('orders', 'fulfilled_by')) {
                $table->unsignedBigInteger('fulfilled_by')->nullable()->after('fulfilled_at');
                $table->foreign('fulfilled_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'fulfilled_by')) {
                $table->dropForeign(['fulfilled_by']);
                $table->dropColumn('fulfilled_by');
            }
            foreach (['fulfilled_at', 'fulfillment_status', 'public_id'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
