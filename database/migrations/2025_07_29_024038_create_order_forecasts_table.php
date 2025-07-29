<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderForecastsTable extends Migration
{
    public function up()
    {
        Schema::create('order_forecasts', function (Blueprint $table) {
            $table->id(); // 1

            // Previsão
            $table->date('forecast_date'); // 2
            $table->time('forecast_time'); // 3
            $table->unsignedBigInteger('entity_id'); // 4
            $table->string('entity_name', 100)->nullable(); // 5
            $table->string('customer_name_forecast')->nullable(); // 6
            $table->string('origin_forecast')->nullable(); // 7
            $table->string('fulfillment_forecast')->nullable(); // 8
            $table->string('items_forecast', 1024)->nullable(); // 9
            $table->decimal('total_forecast', 8, 2)->default(0); // 10
            $table->string('payment_method_forecast')->nullable(); // 11
            $table->text('notes_forecast')->nullable(); // 12

            // Após fechamento real do dia, campos de comparação
            $table->unsignedBigInteger('order_id')->nullable(); // 13
            $table->dateTime('order_datetime_real')->nullable(); // 14
            $table->string('customer_name_real')->nullable(); // 15
            $table->string('origin_real')->nullable(); // 16
            $table->string('fulfillment_real')->nullable(); // 17
            $table->string('items_real', 1024)->nullable(); // 18
            $table->decimal('total_real', 8, 2)->nullable(); // 19
            $table->string('payment_method_real')->nullable(); // 20
            $table->text('notes_real')->nullable(); // 21

            // Métricas de acerto
            $table->boolean('hit_customer_name')->default(false); // 22
            $table->boolean('hit_origin')->default(false); // 23
            $table->boolean('hit_fulfillment')->default(false); // 24
            $table->boolean('hit_items')->default(false); // 25
            $table->boolean('hit_payment_method')->default(false); // 26
            $table->boolean('hit_notes')->default(false); // 27
            $table->decimal('accuracy_value', 5, 2)->default(0); // 28
            $table->decimal('diff_total', 8, 2)->default(0); // 29
            $table->integer('score')->default(0); // 30

            // Contexto/entrada
            $table->json('input_data')->nullable(); // 31
            $table->string('status')->default('pending'); // 32

            // Campos avaliativos/avançados
            $table->string('human_evaluation')->nullable(); // 33
            $table->text('human_feedback')->nullable(); // 34
            $table->decimal('probability_of_approval', 5, 2)->nullable(); // 35
            $table->decimal('model_confidence', 5, 2)->nullable(); // 36
            $table->string('reason_for_prediction')->nullable(); // 37
            $table->integer('historical_similarity')->nullable(); // 38
            $table->boolean('is_recommended')->default(false); // 39
            $table->boolean('is_improbable')->default(false); // 40
            $table->integer('repeat_forecast_count')->default(0); // 41
            $table->string('operational_feedback')->nullable(); // 42

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_forecasts');
    }
}
