<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('app_id')->nullable();

            $table->string('slug')->nullable();
            $table->string('name');
            $table->string('type')->nullable();
            $table->string('sku')->nullable();
            $table->text('description')->nullable();
            $table->integer('duration')->nullable();

            $table->decimal('price', 10, 2)->default(0);
            $table->integer('stock')->default(0);
            $table->boolean('status')->default(true);
            $table->boolean('limited_by_user')->default(false);

            $table->string('category')->nullable();
            $table->string('subcategory')->nullable();
            $table->string('brand')->nullable();

            $table->timestamp('availability_start')->nullable();
            $table->timestamp('availability_end')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_featured')->default(false);

            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_name')->nullable();

            $table->json('tags')->nullable();
            $table->decimal('discount', 10, 2)->nullable();
            $table->timestamp('expiration_date')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('app_id')->references('id')->on('applications')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
