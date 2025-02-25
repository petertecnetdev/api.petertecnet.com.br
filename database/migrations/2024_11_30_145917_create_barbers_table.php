<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBarbersTable extends Migration
{
    public function up()
    {
        Schema::create('barbers', function (Blueprint $table) {
            $table->id(); // id auto-incrementável
            $table->foreignId('user_id')->constrained('users'); // Relaciona com o usuário (gerente da barbearia)
            $table->string('first_name'); // Nome
            $table->string('last_name'); // Sobrenome
            $table->string('email')->unique(); // Email único
            $table->string('slug')->nullable();  
            $table->string('phone'); // Telefone
            $table->json('barbershops')->nullable(); // Campo JSON para lista de barbearias
            $table->string('avatar')->nullable(); // Imagem de perfil (opcional)
            $table->text('bio')->nullable(); // Biografia (opcional)
            $table->text('specialties')->nullable(); // Especialidades (opcional)
            $table->integer('experience_years')->nullable(); // Anos de experiência (opcional)
            $table->json('working_hours')->nullable(); // Horário de trabalho
            $table->enum('status', ['active', 'inactive', 'on_leave'])->default('active'); // Status
            $table->float('rating', 3, 2)->nullable(); // Avaliação média (opcional)
            $table->string('price_range')->nullable(); // Faixa de preço (opcional)
            $table->json('social_media_links')->nullable(); // Links para redes sociais (opcional)
            $table->string('languages')->nullable(); // Idiomas falados (opcional)
            $table->json('certifications')->nullable(); // Certificados ou treinamentos (opcional)
            $table->timestamps(); // created_at, updated_at
            $table->softDeletes(); // deleted_at (para soft delete)
        });

    }

    public function down()
    {
        Schema::dropIfExists('barbers'); // Remover a tabela de barbeiros
    }
}
