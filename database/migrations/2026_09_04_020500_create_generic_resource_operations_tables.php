<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_financial_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('resource_type', 80)->index();
            $table->unsignedBigInteger('resource_id')->index();
            $table->string('direction', 16)->index(); // income | expense
            $table->string('category', 80)->nullable()->index();
            $table->string('description', 190);
            $table->decimal('amount', 14, 2);
            $table->date('due_on')->nullable()->index();
            $table->dateTime('paid_at')->nullable()->index();
            $table->string('status', 32)->default('pending')->index();
            $table->string('recurrence', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['app_id', 'resource_type', 'resource_id'], 'rfe_resource_scope_idx');
        });

        Schema::create('resource_operations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('resource_type', 80)->index();
            $table->unsignedBigInteger('resource_id')->index();
            $table->string('operation_type', 48)->index(); // task | maintenance | inspection | document | follow_up
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('open')->index();
            $table->string('priority', 16)->default('normal')->index();
            $table->dateTime('due_at')->nullable()->index();
            $table->decimal('estimated_amount', 14, 2)->nullable();
            $table->decimal('actual_amount', 14, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['app_id', 'resource_type', 'resource_id'], 'ro_resource_scope_idx');
        });

        Schema::create('portfolio_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('key', 80);
            $table->json('value');
            $table->timestamps();
            $table->unique(['app_id', 'user_id', 'key'], 'portfolio_preferences_scope_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_preferences');
        Schema::dropIfExists('resource_operations');
        Schema::dropIfExists('resource_financial_entries');
    }
};
