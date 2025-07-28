<?php
// database/migrations/2024_11_30_190000_create_barber_barbershop_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBarberBarbershopTable extends Migration
{
    public function up(): void
    {
        // Garante que não exista de tentativas anteriores
        Schema::dropIfExists('barber_barbershop');

        Schema::create('barber_barbershop', function (Blueprint $table) {
            $table->id();

            $table->foreignId('barber_id')
                  ->constrained('barbers')
                  ->cascadeOnDelete();

            $table->foreignId('barbershop_id')
                  ->constrained('barbershops')
                  ->cascadeOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('barber_barbershop', function (Blueprint $table) {
            $table->dropForeign(['barber_id']);
            $table->dropForeign(['barbershop_id']);
        });
        Schema::dropIfExists('barber_barbershop');
    }
}
