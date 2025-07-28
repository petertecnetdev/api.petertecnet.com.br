<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('app_id')->nullable();
            $table->string('entity_name')->nullable(); // ex: 'establishment'
            $table->unsignedBigInteger('entity_id')->nullable();

            $table->string('order_number')->nullable();
            $table->timestamp('order_datetime')->nullable();

            $table->unsignedBigInteger('attendant_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();

            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('access_code')->nullable();
            $table->string('origin')->nullable(); // ex: 'mesa', 'balcão', 'whatsapp'
            $table->string('fulfillment')->nullable(); // ex: 'retirada', 'entrega'
            $table->string('payment_status')->nullable(); // ex: 'pendente', 'pago'
            $table->string('payment_method')->nullable(); // ex: 'pix', 'dinheiro'
            $table->decimal('total_price', 10, 2)->default(0);
            $table->string('status')->nullable(); // ex: 'novo', 'preparando', 'concluído'

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('app_id')->references('id')->on('applications')->nullOnDelete();
            $table->foreign('attendant_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('client_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
