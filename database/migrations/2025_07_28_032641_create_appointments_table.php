<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('app_id');
            $table->string('entity_name');
            $table->unsignedBigInteger('entity_id');

            $table->dateTime('scheduled_at');
            $table->dateTime('expected_end_time')->nullable();

            $table->json('service_ids')->nullable();

            $table->unsignedBigInteger('provider_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('registered_by')->nullable();

            $table->string('status')->nullable();
            $table->string('location')->nullable();
            $table->integer('duration')->nullable();
            $table->text('notes')->nullable();

            $table->string('payment_status')->nullable();
            $table->string('appointment_type')->nullable();
            $table->string('attendance_status')->nullable();
            $table->string('client_confirmation')->nullable();

            $table->json('info')->nullable();

            $table->timestamps();

            $table->foreign('app_id')
                  ->references('id')->on('applications')
                  ->cascadeOnDelete();

            // <<< CORREÇÃO AQUI: agora referencia `id` em employers >>>
            $table->foreign('provider_id')
                  ->references('id')->on('employers')
                  ->nullOnDelete();

            $table->foreign('client_id')
                  ->references('id')->on('users')
                  ->nullOnDelete();

            $table->foreign('registered_by')
                  ->references('id')->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
