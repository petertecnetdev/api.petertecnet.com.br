<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInteractionsTable extends Migration
{
    public function up()
    {
        Schema::create('interactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_type'); // Pode ser 'event', 'blog', 'production', etc.
            $table->string('interaction_type'); // Tipos de interações possíveis
            $table->text('comment')->nullable(); // Pode ser nulo para interações que não são comentários
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            // Você também pode criar chaves estrangeiras para as entidades específicas (eventos, blogs, produções, etc.)
        });
    }

    public function down()
    {
        Schema::dropIfExists('interactions');
    }
}
