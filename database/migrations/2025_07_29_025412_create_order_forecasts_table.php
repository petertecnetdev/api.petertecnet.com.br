<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderForecastsTable extends Migration
{
    public function up(): void
    {
        Schema::create('order_forecasts', function (Blueprint $table) {
            $table->id();

            // --- previsão ---
            $table->date('forecast_date');
            $table->time('forecast_time');
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_name', 100)->nullable();
            $table->string('customer_name_forecast')->nullable();
            $table->string('origin_forecast')->nullable();
            $table->string('fulfillment_forecast')->nullable();
            $table->json('items_forecast')->nullable();
            $table->decimal('total_forecast', 8, 2)->default(0);
            $table->string('payment_method_forecast')->nullable();
            $table->text('notes_forecast')->nullable();

            // --- dados reais ---
            $table->unsignedBigInteger('order_id')->nullable();
            $table->dateTime('order_datetime_real')->nullable();
            $table->string('customer_name_real')->nullable();
            $table->string('origin_real')->nullable();
            $table->string('fulfillment_real')->nullable();
            $table->json('items_real')->nullable();
            $table->decimal('total_real', 8, 2)->nullable();
            $table->string('payment_method_real')->nullable();
            $table->text('notes_real')->nullable();

            // --- métricas de acerto ---
            $table->boolean('hit_customer_name')->default(false);
            $table->boolean('hit_origin')->default(false);
            $table->boolean('hit_fulfillment')->default(false);
            $table->boolean('hit_items')->default(false);
            $table->boolean('hit_payment_method')->default(false);
            $table->boolean('hit_notes')->default(false);
            $table->decimal('accuracy_value', 5, 2)->default(0);
            $table->decimal('diff_total', 8, 2)->default(0);
            $table->integer('score')->default(0);

            // --- contexto de entrada ---
            $table->json('input_data')->nullable();

            // --- status e usuário ---
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('user_id')->nullable();

            // --- campos avançados / humanos (opcionais) ---
            $table->string('human_evaluation')->nullable();
            $table->text('human_feedback')->nullable();
            $table->decimal('probability_of_approval', 5, 2)->nullable();
            $table->decimal('model_confidence', 5, 2)->nullable();
            $table->string('reason_for_prediction')->nullable();
            $table->integer('historical_similarity')->nullable();
            $table->boolean('is_recommended')->default(false);
            $table->boolean('is_improbable')->default(false);
            $table->integer('repeat_forecast_count')->default(0);
            $table->string('operational_feedback')->nullable();

            $table->timestamps();

            // se quiser, ative as FKs:
            // $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            // $table->foreign('order_id')->references('id')->on('orders')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_forecasts');
    }
}
