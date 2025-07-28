<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Desativa temporariamente as checagens de FKs para dropar tabela sem erro
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('barbershops');
        Schema::enableForeignKeyConstraints();

        Schema::create('barbershops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('description')->nullable();
            $table->text('instagram')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->string('address');
            $table->string('city');
            $table->string('state');
            $table->string('zipcode');
            $table->string('website')->nullable();
            $table->text('location')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->decimal('rating', 2, 1)->default(0);
            $table->tinyInteger('status')->default(1);

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->foreignId('updated_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->string('logo')->nullable();
            $table->string('background_image')->nullable();
            $table->text('terms_of_service')->nullable();
            $table->json('social_media_links')->nullable();
            $table->json('barbers')->nullable();

            $table->text('schema_markup')->nullable();
            $table->string('language')->nullable();
            $table->string('hreflang')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->string('meta_author')->nullable();
            $table->string('meta_robots')->nullable();
            $table->string('canonical_url')->nullable();

            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image')->nullable();

            $table->string('twitter_title')->nullable();
            $table->text('twitter_description')->nullable();
            $table->string('twitter_image')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('barbershops');
        Schema::enableForeignKeyConstraints();
    }
};
