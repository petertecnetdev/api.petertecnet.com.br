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
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Responsável pelo cadastro do item
            $table->string('name');
            $table->string('type'); // Ex: ingresso, bebida, cigarro, combo, etc.
            $table->text('description')->nullable();
            $table->decimal('price', 8, 2);
            $table->integer('stock')->default(0);
            $table->boolean('status')->default(true); // Ativo/Inativo
            $table->integer('limited_by_user')->default(0); // Limite por usuário
            $table->string('category')->nullable(); // Categoria do item
            $table->dateTime('availability_start')->nullable(); // Início da disponibilidade
            $table->dateTime('availability_end')->nullable(); // Fim da disponibilidade
            $table->string('image')->nullable(); // URL da imagem do item
            $table->boolean('is_featured')->default(false); // Destaque do item
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('items');
    }
};
