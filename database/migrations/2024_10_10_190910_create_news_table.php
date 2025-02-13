<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNewsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->string('title'); // Título da notícia
            $table->text('content'); // Conteúdo da notícia
            $table->string('image')->nullable(); // Caminho da imagem associada à notícia
            $table->timestamp('published_at')->useCurrent(); // Data de publicação
            $table->unsignedBigInteger('user_id')->nullable(); // Permitir que user_id seja nulo
            $table->timestamps(); // Campos created_at e updated_at

            // Chave estrangeira para o autor da notícia
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('news');
    }
}
