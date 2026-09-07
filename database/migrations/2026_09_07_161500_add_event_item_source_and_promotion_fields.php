<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = DB::getDriverName() === 'sqlite' ? 'event_items' : 'cutinapp_event_items';

        Schema::table($table, function (Blueprint $table) {
            $table->unsignedBigInteger('source_item_id')->nullable()->after('event_id')->index();
            $table->boolean('promotion_enabled')->default(false)->after('quantity');
            $table->decimal('promotion_price', 12, 2)->nullable()->after('promotion_enabled');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('CREATE OR REPLACE ALGORITHM=MERGE VIEW event_items AS SELECT * FROM cutinapp_event_items');
        }
    }

    public function down(): void
    {
        $table = DB::getDriverName() === 'sqlite' ? 'event_items' : 'cutinapp_event_items';

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('DROP VIEW IF EXISTS event_items');
        }

        Schema::table($table, function (Blueprint $table) {
            $table->dropColumn(['source_item_id', 'promotion_enabled', 'promotion_price']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('CREATE OR REPLACE ALGORITHM=MERGE VIEW event_items AS SELECT * FROM cutinapp_event_items');
        }
    }
};
