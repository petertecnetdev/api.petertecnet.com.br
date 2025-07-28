<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('establishments', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('fantasy')->nullable();
            $table->string('slug')->unique();
            $table->string('cnpj')->nullable();
            $table->string('type')->nullable();
            $table->string('category')->nullable();

            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('description')->nullable();
            $table->text('additional_info')->nullable();

            $table->string('city')->nullable();
            $table->text('location')->nullable();
            $table->string('cep')->nullable();
            $table->string('address')->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->string('logo')->nullable();
            $table->string('background')->nullable();

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_cancelled')->default(false);

            $table->string('website_url')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('twitter_url')->nullable();
            $table->string('youtube_url')->nullable();

            $table->json('segments')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishments');
    }
};
