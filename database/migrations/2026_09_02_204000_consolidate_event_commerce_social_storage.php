<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RENAMES = [
        'cutinapp_artists' => 'artists',
        'cutinapp_event_artist' => 'event_artist',
        'cutinapp_follows' => 'follows',
        'cutinapp_event_engagements' => 'event_engagements',
        'cutinapp_user_preferences' => 'application_user_preferences',
        'cutinapp_artist_members' => 'artist_members',
        'cutinapp_artist_claims' => 'artist_claims',
        'cutinapp_event_ratings' => 'event_ratings',
        'cutinapp_event_reports' => 'event_reports',
        'cutinapp_event_posts' => 'event_posts',
        'cutinapp_event_post_likes' => 'event_post_likes',
        'cutinapp_event_items' => 'event_items',
        'cutinapp_producer_payment_accounts' => 'merchant_payment_accounts',
        'cutinapp_orders' => 'commerce_orders',
        'cutinapp_order_items' => 'commerce_order_items',
        'cutinapp_inventory_reservations' => 'inventory_reservations',
        'cutinapp_payments' => 'commerce_payments',
        'cutinapp_ledger_entries' => 'ledger_entries',
        'cutinapp_payout_requests' => 'payout_requests',
        'cutinapp_producer_contract_acceptances' => 'contract_acceptances',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $legacy => $domain) {
            if (Schema::hasTable($legacy) && ! Schema::hasTable($domain)) {
                Schema::rename($legacy, $domain);
            }
        }

        if (Schema::hasTable('event_passes') && Schema::hasColumn('event_passes', 'cutinapp_order_item_id') && ! Schema::hasColumn('event_passes', 'commerce_order_item_id')) {
            Schema::table('event_passes', function (Blueprint $table) {
                $table->renameColumn('cutinapp_order_item_id', 'commerce_order_item_id');
            });
        }

        if (Schema::hasTable('merchant_payment_accounts') && ! Schema::hasColumn('merchant_payment_accounts', 'app_id')) {
            Schema::table('merchant_payment_accounts', function (Blueprint $table) {
                $table->foreignId('app_id')->nullable()->after('id')->constrained('applications')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('merchant_payment_accounts') && Schema::hasColumn('merchant_payment_accounts', 'app_id')) {
            Schema::table('merchant_payment_accounts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('app_id');
            });
        }

        if (Schema::hasTable('event_passes') && Schema::hasColumn('event_passes', 'commerce_order_item_id') && ! Schema::hasColumn('event_passes', 'cutinapp_order_item_id')) {
            Schema::table('event_passes', function (Blueprint $table) {
                $table->renameColumn('commerce_order_item_id', 'cutinapp_order_item_id');
            });
        }

        foreach (array_reverse(self::RENAMES, true) as $legacy => $domain) {
            if (Schema::hasTable($domain) && ! Schema::hasTable($legacy)) {
                Schema::rename($domain, $legacy);
            }
        }
    }
};
