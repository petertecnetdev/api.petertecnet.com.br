<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutinapp_event_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name', 140);
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('quantity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['event_id', 'is_active']);
        });

        Schema::create('cutinapp_producer_payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_id')->unique()->constrained('productions')->cascadeOnDelete();
            $table->string('provider', 40)->default('mercadopago');
            $table->string('status', 30)->default('pending');
            $table->string('provider_recipient_id', 255)->nullable()->index();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['provider', 'status']);
        });

        Schema::create('cutinapp_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('production_id')->constrained('productions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('pending');
            $table->char('currency', 3)->default('BRL');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('platform_fee', 12, 2)->default(0);
            $table->decimal('processor_fee', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('producer_net', 12, 2)->default(0);
            $table->string('payment_method', 30)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['production_id', 'status']);
            $table->index(['event_id', 'status']);
        });

        Schema::create('cutinapp_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('cutinapp_orders')->cascadeOnDelete();
            $table->string('type', 20);
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->restrictOnDelete();
            $table->foreignId('event_item_id')->nullable()->constrained('cutinapp_event_items')->restrictOnDelete();
            $table->string('name', 160);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('subtotal', 12, 2);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['type', 'ticket_id']);
            $table->index(['type', 'event_item_id']);
        });

        Schema::table('event_passes', function (Blueprint $table) {
            $table->foreignId('cutinapp_order_item_id')->nullable()->after('ticket_id')->constrained('cutinapp_order_items')->nullOnDelete();
            $table->index(['cutinapp_order_item_id', 'status']);
        });

        Schema::create('cutinapp_inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('cutinapp_orders')->cascadeOnDelete();
            $table->string('type', 20);
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->restrictOnDelete();
            $table->foreignId('event_item_id')->nullable()->constrained('cutinapp_event_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['expires_at', 'released_at']);
        });

        Schema::create('cutinapp_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('cutinapp_orders')->cascadeOnDelete();
            $table->string('provider', 40)->default('mercadopago');
            $table->string('method', 30);
            $table->string('status', 30)->default('pending');
            $table->string('provider_payment_id', 255)->nullable()->index();
            $table->string('provider_txid', 255)->nullable()->unique();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->decimal('amount', 12, 2);
            $table->decimal('provider_fee', 12, 2)->default(0);
            $table->text('qr_code')->nullable();
            $table->text('qr_code_image')->nullable();
            $table->text('ticket_url')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index(['provider', 'status']);
        });

        Schema::create('cutinapp_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_id')->constrained('productions')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('cutinapp_orders')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('cutinapp_payments')->nullOnDelete();
            $table->string('type', 40);
            $table->string('status', 30)->default('posted');
            $table->decimal('amount', 12, 2);
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['production_id', 'type', 'status']);
            $table->index(['payment_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cutinapp_ledger_entries');
        Schema::dropIfExists('cutinapp_payments');
        Schema::dropIfExists('cutinapp_inventory_reservations');
        Schema::table('event_passes', function (Blueprint $table) {
            $table->dropForeign(['cutinapp_order_item_id']);
            $table->dropIndex(['cutinapp_order_item_id', 'status']);
            $table->dropColumn('cutinapp_order_item_id');
        });
        Schema::dropIfExists('cutinapp_order_items');
        Schema::dropIfExists('cutinapp_orders');
        Schema::dropIfExists('cutinapp_producer_payment_accounts');
        Schema::dropIfExists('cutinapp_event_items');
    }
};