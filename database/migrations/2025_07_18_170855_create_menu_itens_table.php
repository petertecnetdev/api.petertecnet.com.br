<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMenuItensTable extends Migration
{
    public function up()
    {
        Schema::create('menu_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('menu')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->decimal('price_override', 10, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['menu_id', 'item_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('menu_itens');
    }
}
