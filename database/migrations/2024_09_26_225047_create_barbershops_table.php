<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBarbershopsTable extends Migration
{
    public function up()
    {
        Schema::create('barbershops', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Nome da barbearia
            $table->string('email')->unique(); // Email da barbearia
            $table->string('phone')->nullable(); // Telefone da barbearia
            $table->text('description')->nullable(); // Descrição da barbearia
            $table->string('address'); // Endereço
            $table->string('city'); // Cidade
            $table->string('state'); // Estado
            $table->string('zipcode'); // CEP
            $table->string('website')->nullable(); // Website da barbearia
            $table->string('latitude')->nullable(); // Latitude como string
            $table->string('longitude')->nullable(); // Longitude como string
            $table->decimal('rating', 2, 1)->default(0); // Avaliação média
            $table->integer('status')->default(1); // Status da barbearia
            $table->foreignId('user_id')->constrained('users'); // Relaciona com o usuário (gerente da barbearia)
            $table->foreignId('created_by')->nullable()->constrained('users'); // Criado por
            $table->foreignId('updated_by')->nullable()->constrained('users'); // Atualizado por
            $table->string('logo')->nullable(); // Logo da barbearia
            $table->string('background_image')->nullable(); // Imagem de fundo
            $table->text('terms_of_service')->nullable(); // Termos de serviço
            $table->json('social_media_links')->nullable(); // Links de redes sociais
            $table->timestamps(); // Cria as colunas created_at e updated_at
        });
    }

    public function down()
    {
        Schema::dropIfExists('barbershops');
    }
}
