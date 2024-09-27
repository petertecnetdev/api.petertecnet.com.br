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
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null'); // Responsável pelo cadastro do item
            $table->foreignId('app_id')->nullable()->default(null); // Referência ao aplicativo

            $table->string('name');
            $table->string('type'); // Ex: ingresso, bebida, serviço, produto, etc.
            $table->string('sku')->unique()->nullable(); // Código do item
            $table->text('description')->nullable();
            $table->decimal('price', 8, 2);
            $table->integer('stock')->default(0);
            $table->boolean('status')->default(true); // Ativo/Inativo
            $table->integer('limited_by_user')->default(0); // Limite por usuário
            $table->string('category')->nullable(); // Categoria do item
            $table->string('subcategory')->nullable(); // Categoria do item
            $table->string('brand')->nullable(); // Marca do item
            $table->dateTime('availability_start')->nullable(); // Início da disponibilidade
            $table->dateTime('availability_end')->nullable(); // Fim da disponibilidade
            $table->string('image')->nullable(); // URL da imagem do item
            $table->boolean('is_featured')->default(false); // Destaque do item
            
            $table->string('slug')->nullable();
            // Novos campos para relação genérica
            $table->unsignedBigInteger('entity_id'); // ID da entidade relacionada
            $table->string('entity_name'); // Nome da entidade (ex: 'event', 'service', etc.)
            
            // Campos adicionais
            $table->json('tags')->nullable(); // Tags relacionadas ao item
            $table->decimal('discount', 5, 2)->nullable(); // Desconto aplicável ao item
            $table->dateTime('expiration_date')->nullable(); // Data de expiração
            $table->text('notes')->nullable(); // Notas adicionais sobre o item
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null'); // Criador do item
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null'); // Último atualizador do item

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
