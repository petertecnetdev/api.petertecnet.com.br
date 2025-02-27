<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductionsTable extends Migration
{
    public function up()
    {
        Schema::create('productions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('fantasy')->nullable();
            $table->string('cnpj');
            $table->string('type')->nullable();
            $table->string('slug')->nullable();
            $table->string('establishment_type')->nullable();
            $table->string('phone')->nullable();
            $table->string('segments')->nullable();
            $table->text('description')->nullable();
            $table->text('location')->nullable();
            $table->string('cep')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('uf')->nullable();
            $table->string('country')->nullable();
            $table->string('logo')->nullable();
            $table->string('background')->nullable();
            $table->integer('capacity')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('is_approved')->default(false);
            $table->decimal('ticket_price_min', 10, 2)->nullable();
            $table->decimal('ticket_price_max', 10, 2)->nullable();
            $table->integer('total_tickets_sold')->nullable();
            $table->integer('total_tickets_available')->nullable();
            $table->boolean('is_cancelled')->default(false);
            $table->text('additional_info')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('website_url')->nullable();
            $table->string('twitter_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('youtube_url')->nullable();
            $table->text('other_information')->nullable();
            $table->json('images')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('productions');
    }
}
