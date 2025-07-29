<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderForecastsTable extends Migration
{
    public function up()
    {
        Schema::create('order_forecasts', function (Blueprint $table) {
            $table->id();

            // Previsão
            $table->date('forecast_date'); // Data prevista
            $table->time('forecast_time'); // Hora prevista
            $table->unsignedBigInteger('entity_id'); // Estabelecimento
            $table->string('customer_name_forecast')->nullable();
            $table->string('origin_forecast')->nullable();
            $table->string('fulfillment_forecast')->nullable();
            $table->string('items_forecast', 1024)->nullable(); // JSON string (id, qty, nome)
            $table->decimal('total_forecast', 8, 2)->default(0);
            $table->string('payment_method_forecast')->nullable();
            $table->text('notes_forecast')->nullable();

            // Após fechamento real do dia, campos de comparação
            $table->unsignedBigInteger('order_id')->nullable(); // id do pedido real que mais se encaixou
            $table->dateTime('order_datetime_real')->nullable();
            $table->string('customer_name_real')->nullable();
            $table->string('origin_real')->nullable();
            $table->string('fulfillment_real')->nullable();
            $table->string('items_real', 1024)->nullable(); // JSON string
            $table->decimal('total_real', 8, 2)->nullable();
            $table->string('payment_method_real')->nullable();
            $table->text('notes_real')->nullable();

            // Métricas de acerto
            $table->boolean('hit_customer_name')->default(false);
            $table->boolean('hit_origin')->default(false);
            $table->boolean('hit_fulfillment')->default(false);
            $table->boolean('hit_items')->default(false);
            $table->boolean('hit_payment_method')->default(false);
            $table->boolean('hit_notes')->default(false);
            $table->decimal('accuracy_value', 5, 2)->default(0); // Ex: 85.5 (%)
            $table->decimal('diff_total', 8, 2)->default(0); // Diferença valor previsto vs real
            $table->integer('score')->default(0); // Pontuação de acerto desse forecast

            // Contexto/entrada
            $table->json('input_data')->nullable(); // Contexto usado na previsão (pode ser histórico, parâmetros etc)
            $table->string('status')->default('pending'); // pending, matched, evaluated

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_forecasts');
    }
}
