<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cutinapp_event_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->enum('role', ['producer','manager','promoter','supplier','collaborator','checker'])->default('collaborator');
            $table->enum('status', ['invited','active','suspended','removed'])->default('active');
            $table->json('permissions')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['event_id','role','status']);
            $table->unique(['event_id','user_id','role'], 'cutin_member_user_role_unique');
        });

        Schema::create('cutinapp_promoters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('cutinapp_event_members')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 40)->unique();
            $table->enum('commission_type', ['percentage','fixed'])->default('percentage');
            $table->decimal('commission_value', 10, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['event_id','active']);
        });

        Schema::create('cutinapp_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 60)->nullable();
            $table->enum('discount_type', ['percentage','fixed'])->default('percentage');
            $table->decimal('discount_value', 10, 2);
            $table->decimal('minimum_amount', 10, 2)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id','code']);
        });

        Schema::create('cutinapp_sales', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('buyer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('promoter_id')->nullable()->constrained('cutinapp_promoters')->nullOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained('cutinapp_promotions')->nullOnDelete();
            $table->string('buyer_name');
            $table->string('buyer_email');
            $table->string('buyer_document')->nullable();
            $table->string('buyer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('fee', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('commission_total', 12, 2)->default(0);
            $table->enum('payment_method', ['pix','credit_card','debit_card','cash','free','external'])->default('pix');
            $table->enum('payment_status', ['pending','paid','failed','refunded','cancelled'])->default('pending');
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['event_id','payment_status','created_at']);
        });

        Schema::create('cutinapp_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('cutinapp_sales')->cascadeOnDelete();
            $table->enum('item_type', ['ticket','product']);
            $table->unsignedBigInteger('reference_id');
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total_price', 12, 2);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['item_type','reference_id']);
        });

        Schema::create('cutinapp_admissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('sale_id')->constrained('cutinapp_sales')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained('cutinapp_sale_items')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->unsignedBigInteger('ticket_id');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('holder_name');
            $table->string('holder_email');
            $table->enum('status', ['valid','used','cancelled','refunded'])->default('valid');
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['event_id','status']);
        });

        Schema::create('cutinapp_commission_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promoter_id')->constrained('cutinapp_promoters')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('cutinapp_sales')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['pending','available','paid','cancelled'])->default('pending');
            $table->timestamp('available_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payout_reference')->nullable();
            $table->timestamps();
            $table->unique(['promoter_id','sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cutinapp_commission_ledger');
        Schema::dropIfExists('cutinapp_admissions');
        Schema::dropIfExists('cutinapp_sale_items');
        Schema::dropIfExists('cutinapp_sales');
        Schema::dropIfExists('cutinapp_promotions');
        Schema::dropIfExists('cutinapp_promoters');
        Schema::dropIfExists('cutinapp_event_members');
    }
};
