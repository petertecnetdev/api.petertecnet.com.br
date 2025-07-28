<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
       Schema::create('order_items', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('order_id');
    $table->unsignedBigInteger('item_id')->nullable();
    $table->integer('quantity');
    $table->decimal('unit_price', 10, 2);
    $table->decimal('subtotal', 10, 2);
    $table->timestamps();

    $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
    $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();
});

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('order_items');
    }
};
