<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('interactions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_type')->nullable(); // ex: 'establishment', 'production', 'blog'
            $table->string('interaction_type')->nullable(); // ex: 'like', 'comment', 'rating'

            $table->text('comment')->nullable();
            $table->string('name')->nullable();
            $table->text('content')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interactions');
    }
};
