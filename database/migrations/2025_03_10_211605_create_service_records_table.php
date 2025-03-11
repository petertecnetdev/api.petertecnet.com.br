<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServiceRecordsTable extends Migration
{
    public function up()
    {
        Schema::create('service_records', function (Blueprint $table) {
            $table->id(); // ID da tabela
            $table->foreignId('app_id')->constrained('applications'); // ID da aplicação
            $table->string('entity_name'); // Nome da entidade (ex: barbershop, hospital, escritório de advocacia)
            $table->foreignId('entity_id'); // ID da entidade (ex: barbearia, clínica, empresa)
            $table->json('service_ids')->nullable(); // Lista de serviços prestados
            $table->foreignId('provider_id')->constrained('users'); // ID do prestador do serviço (barbeiro, advogado, médico, etc.)
            $table->foreignId('client_id')->nullable()->constrained('users'); // ID do cliente/paciente
            $table->foreignId('registered_by')->constrained('users'); // Quem registrou o atendimento
            $table->decimal('discount', 10, 2)->default(0); // Valor do desconto aplicado
            $table->enum('payment_method', [
                'Pix',
                'Débito',
                'Crédito',
                'Dinheiro',
                'Fiado',
                'Cortesia',
                'Transferência bancária',
                'Vale-refeição',
                'Cheque',
                'PayPal'
            ]); // Tipo de pagamento
            $table->decimal('total_price', 10, 2); // Valor total do atendimento
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending'); // Status do serviço prestado
            $table->text('notes')->nullable(); // Notas adicionais
            $table->timestamps(); // Created at, Updated at
        });
    }

    public function down()
    {
        Schema::dropIfExists('service_records');
    }
}
