<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cutinapp_producer_payment_accounts')) return;

        DB::table('cutinapp_producer_payment_accounts')
            ->where('provider', 'mercadopago')
            ->where('status', 'connected')
            ->update([
                'status' => 'legacy_disabled',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('cutinapp_producer_payment_accounts')) return;

        DB::table('cutinapp_producer_payment_accounts')
            ->where('provider', 'mercadopago')
            ->where('status', 'legacy_disabled')
            ->update([
                'status' => 'connected',
                'updated_at' => now(),
            ]);
    }
};
