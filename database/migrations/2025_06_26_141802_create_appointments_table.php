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
            $table->json('service_ids');
            $table->unsignedBigInteger('provider_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('registered_by');
            $table->string('status', 50);
            $table->string('location')->nullable();
            $table->integer('duration');
            $table->text('notes')->nullable();
            $table->string('payment_status', 50)->nullable();
            $table->string('appointment_type', 50)->nullable();
            $table->string('attendance_status')->nullable();
            $table->boolean('client_confirmation')->default(false);
            $table->json('info')->nullable();
            $table->timestamps();

            $table->foreign('app_id')->references('id')->on('applications')->onDelete('cascade');
            $table->foreign('provider_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('registered_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
