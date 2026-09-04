<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('lease_id')->nullable()->index();
            $table->unsignedBigInteger('property_id')->nullable()->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('type', 64)->index();
            $table->string('status', 32)->default('open')->index();
            $table->string('priority', 16)->default('normal')->index();
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->dateTime('due_at')->nullable()->index();
            $table->dateTime('occurred_at')->nullable()->index();
            $table->dateTime('completed_at')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_id', 'type', 'status']);
            $table->index(['app_id', 'lease_id', 'type']);
            $table->index(['app_id', 'property_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_operations');
    }
};
