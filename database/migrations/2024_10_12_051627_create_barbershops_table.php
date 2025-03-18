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
            $table->string('name'); // Nome da barbearia
            $table->string('email')->nullable();  // Email da barbearia
            $table->string('phone')->nullable(); // Telefone da barbearia
            $table->text('description')->nullable(); // Descrição da barbearia
            $table->text('instagram')->nullable(); // Instagram da barbearia
            $table->string('slug')->nullable();
            $table->string('address'); // Endereço
            $table->string('city'); // Cidade
            $table->string('state'); // Estado
            $table->string('zipcode'); // CEP
            $table->string('website')->nullable();
            $table->text('location')->nullable(); // Localização da barbearia
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
            $table->json('barbers')->nullable(); // Campo JSON para lista de barbeiros

            // Campos para dados estruturados e multilíngue
            $table->text('schema_markup')->nullable(); // Armazena JSON-LD ou outros dados estruturados (schema.org) para SEO
            $table->string('language')->nullable();      // Define o idioma do conteúdo (ex: 'pt-BR')
            $table->string('hreflang')->nullable();        // Código hreflang para versões em diferentes idiomas

            // Novos campos para SEO e metadados (usados no head do HTML)
            $table->string('meta_title')->nullable(); // Título meta para SEO (exibido na tag <title>)
            $table->text('meta_description')->nullable(); // Descrição meta para SEO (meta description)
            $table->string('meta_keywords')->nullable(); // Palavras-chave para SEO (meta keywords)
            $table->string('meta_author')->nullable(); // Autor meta (meta author, opcional)
            $table->string('meta_robots')->nullable(); // Instruções para os robôs dos motores de busca (ex: index, noindex)
            $table->string('canonical_url')->nullable(); // URL canônica para evitar conteúdo duplicado

            // Campos para Open Graph (redes sociais)
            $table->string('og_title')->nullable(); // Título para Open Graph
            $table->text('og_description')->nullable(); // Descrição para Open Graph
            $table->string('og_image')->nullable(); // URL da imagem para Open Graph

            // Campos para Twitter Cards
            $table->string('twitter_title')->nullable(); // Título para Twitter Card
            $table->text('twitter_description')->nullable(); // Descrição para Twitter Card
            $table->string('twitter_image')->nullable(); // URL da imagem para Twitter Card

            $table->timestamps(); // Cria as colunas created_at e updated_at
        });
    }

    public function down()
    {
        Schema::dropIfExists('barbershops');
    }
}
