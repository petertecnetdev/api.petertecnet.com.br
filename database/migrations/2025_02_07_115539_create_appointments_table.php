<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppointmentsTable extends Migration
{
    public function up()
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id(); // ID da tabela
            $table->foreignId('app_id')->constrained('applications'); // Corrija o nome da tabela aqui
            $table->string('entity_name');
            $table->string('appointment_type', 255)->nullable();
            $table->foreignId('entity_id'); // ID da entidade (pode ser outro recurso, como paciente ou cliente)
            $table->dateTime('scheduled_at'); // Data e hora agendada
            $table->json('service_ids')->nullable();
            $table->foreignId('provider_id')->constrained('users'); // ID do provedor (usuário que irá prestar o serviço)
            $table->text('description')->nullable(); // Descrição do agendamento
            $table->foreignId('client_id')->constrained('users'); // Cliente que solicitou o agendamento
            $table->foreignId('registered_by')->constrained('users'); // Relaciona com o usuário (gerente da barbearia)
            $table->enum('status', ['pendente', 'confirmado', 'cancelado', 'concluído']); // Status do agendamento
            $table->string('location')->nullable(); // Local do atendimento
            $table->integer('duration')->nullable(); // Duração estimada do serviço
            $table->text('notes')->nullable(); // Observações adicionais
            $table->enum('payment_status', ['pendente', 'pago', 'cancelado']); // Status do pagamento
            $table->dateTime('expected_end_time')->nullable(); // Hora de término esperada (calculada com base na duração)
            $table->timestamps(); // Created at, Updated at
        });
    }

    public function down()
    {
        Schema::dropIfExists('appointments');
    }
}
