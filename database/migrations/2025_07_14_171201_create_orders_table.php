<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')
                  ->constrained('applications')
                  ->onDelete('cascade');

            $table->string('entity_name', 255);
            $table->unsignedBigInteger('entity_id');

            $table->string('order_number', 10)->unique();
            $table->timestamp('order_datetime');

            // Atendente que registrou o pedido (administrador da Buddy's)
            $table->foreignId('attendant_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            // Cliente opcional (usuário cadastrado no futuro)
            $table->foreignId('client_id')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');

            // Informações do cliente não cadastrado
            $table->string('customer_name', 255);
            $table->string('customer_phone', 20)->nullable();
            $table->string('access_code', 20); // Senha ou código informado ao cliente

            $table->string('payment_method', 50);
            $table->decimal('total_price', 10, 2);
            $table->string('status', 50);
            $table->text('notes')->nullable();

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
        Schema::dropIfExists('orders');
    }
}
