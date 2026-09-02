<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_items', 'notes')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->string('notes', 500)->nullable()->after('subtotal');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'notes')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        }
    }
};
