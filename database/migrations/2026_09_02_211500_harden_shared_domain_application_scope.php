<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'event_items',
        'commerce_orders',
        'commerce_order_items',
        'inventory_reservations',
        'commerce_payments',
        'ledger_entries',
        'payout_requests',
        'contract_acceptances',
        'merchant_payment_accounts',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'app_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedBigInteger('app_id')->nullable()->index();
                });
            }
        }

        $this->backfillFromEvent('event_items');
        $this->backfillFromEvent('commerce_orders');
        $this->backfillFromOrder('commerce_order_items');
        $this->backfillFromOrder('inventory_reservations');
        $this->backfillFromOrder('commerce_payments');
        $this->backfillFromOrder('ledger_entries');
        $this->backfillFromProduction('payout_requests');
        $this->backfillFromProduction('contract_acceptances');
        $this->backfillFromProduction('merchant_payment_accounts');
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'app_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('app_id');
                });
            }
        }
    }

    private function backfillFromEvent(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'app_id') || ! Schema::hasColumn($table, 'event_id')) return;

        DB::table($table)->whereNull('app_id')->orderBy('id')->chunkById(250, function ($rows) use ($table) {
            foreach ($rows as $row) {
                $appId = DB::table('events')->where('id', $row->event_id)->value('app_id');
                if ($appId) DB::table($table)->where('id', $row->id)->update(['app_id' => $appId]);
            }
        });
    }

    private function backfillFromOrder(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'app_id') || ! Schema::hasColumn($table, 'order_id')) return;

        DB::table($table)->whereNull('app_id')->orderBy('id')->chunkById(250, function ($rows) use ($table) {
            foreach ($rows as $row) {
                $appId = DB::table('commerce_orders')->where('id', $row->order_id)->value('app_id');
                if ($appId) DB::table($table)->where('id', $row->id)->update(['app_id' => $appId]);
            }
        });
    }

    private function backfillFromProduction(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'app_id') || ! Schema::hasColumn($table, 'production_id')) return;

        DB::table($table)->whereNull('app_id')->orderBy('id')->chunkById(250, function ($rows) use ($table) {
            foreach ($rows as $row) {
                $appId = DB::table('productions')->where('id', $row->production_id)->value('app_id');
                if ($appId) DB::table($table)->where('id', $row->id)->update(['app_id' => $appId]);
            }
        });
    }
};
