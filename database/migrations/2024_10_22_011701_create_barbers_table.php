<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('barbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Relacionamento com a tabela users
            $table->json('barbershops')->nullable(); // IDs de barbearias
            $table->string('expertise')->nullable(); // Especialidades
            $table->integer('experience_years')->nullable(); // Anos de experiência
            $table->json('certifications')->nullable(); // Certificações
            $table->decimal('rating', 3, 2)->nullable()->default(0.00); // Avaliação média (ex: 4.5)
            $table->integer('reviews_count')->default(0); // Número de avaliações
            $table->time('availability_start')->nullable(); // Horário de início
            $table->time('availability_end')->nullable(); // Horário de término
            $table->json('days_off')->nullable(); // Dias de folga
            $table->date('vacation_start')->nullable(); // Início das férias
            $table->date('vacation_end')->nullable(); // Fim das férias
            $table->integer('max_clients_per_day')->nullable(); // Máximo de clientes por dia
            $table->json('services')->nullable(); // Serviços oferecidos
            $table->integer('time_per_service')->nullable(); // Tempo médio por serviço (em minutos)
            $table->decimal('commission_percentage', 5, 2)->nullable(); // Percentual de comissão
            $table->decimal('fixed_salary', 10, 2)->nullable(); // Salário fixo
            $table->decimal('earnings', 15, 2)->nullable()->default(0.00); // Ganhos totais
            $table->enum('status', ['active', 'inactive', 'on_vacation'])->default('active'); // Status do barbeiro
            $table->text('bio')->nullable(); // Biografia
            $table->json('portfolio')->nullable(); // Portfólio (ex: URLs de fotos de cortes)
            $table->json('tools')->nullable(); // Ferramentas usadas pelo barbeiro
            $table->timestamps(); // created_at e updated_at
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('barbers');
    }
};
