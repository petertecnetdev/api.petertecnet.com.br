<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBarbersTable extends Migration
{
    public function up(): void
    {
        Schema::create('barbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();        // cada barber referencia um usuário
            $table->string('slug')->unique();
            $table->string('phone')->nullable();
            $table->text('bio')->nullable();
            $table->json('specialties')->nullable();
            $table->integer('experience_years')->nullable();
            $table->json('working_hours')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->decimal('rating', 2, 1)->default(0);
            $table->string('price_range')->nullable();
            $table->json('social_media_links')->nullable();
            $table->json('languages')->nullable();
            $table->json('certifications')->nullable();
            $table->string('avatar')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('barbers');
        Schema::enableForeignKeyConstraints();
    }
}
