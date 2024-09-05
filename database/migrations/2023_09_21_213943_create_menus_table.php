<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMenusTable extends Migration
{
    public function up()
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('production_id');
            $table->json('items'); // Para armazenar os itens do cardápio
            $table->string('name');

            $table->timestamps();

            // Adicionando a relação com a produção
            $table->foreign('production_id')->references('id')->on('productions');
        });
    }

    public function down()
    {
        Schema::dropIfExists('menus');
    }
}
