<?php

// database/migrations/2025_07_28_032254_create_order_item_modifiers_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();

            $table->unsignedBigInteger('modifier_id')->nullable(); // ← precisa ser nullable
            $table->foreign('modifier_id')->references('id')->on('items')->nullOnDelete();

            $table->string('type')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_modifiers');
    }
};
