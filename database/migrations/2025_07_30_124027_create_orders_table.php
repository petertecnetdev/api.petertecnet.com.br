<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // App e entidade
            $table->unsignedBigInteger('app_id')->nullable();
            $table->string('entity_name')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();

            // Número e data/hora do atendimento
            $table->string('order_number')->nullable();
            $table->timestamp('order_datetime')->nullable();

            // Quem registrou e quem prestou o serviço
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('attendant_id')->nullable();

            // Cliente
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_cpf', 14)->nullable();

            // Código interno de acesso
            $table->string('access_code')->nullable();

            // Origem, consumo e pagamento
            $table->string('origin')->nullable();
            $table->string('fulfillment')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('total_price', 10, 2)->default(0);
            $table->string('status')->nullable();

            // Observações
            $table->text('notes')->nullable();

            // Criado/atualizado em
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
