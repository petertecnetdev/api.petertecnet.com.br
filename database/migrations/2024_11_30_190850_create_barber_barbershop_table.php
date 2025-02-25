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
        Schema::create('barber_barbershop', function (Blueprint $table) {
            $table->id();
            
            // Definindo as chaves estrangeiras e suas ações de exclusão
            $table->foreignId('barber_id')
                ->constrained('barbers')  // Tabela barbers
                ->onDelete('cascade');   // Excluir os registros relacionados ao excluir o barbeiro
            
            $table->foreignId('barbershop_id')
                ->constrained('barbershops') // Tabela barbershops
                ->onDelete('cascade');      // Excluir os registros relacionados ao excluir a barbearia
            
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('barber_barbershop');
    }
};
