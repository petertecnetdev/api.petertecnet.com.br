<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEventsTable extends Migration
{
    public function up()
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('production_id');
            $table->string('title');
            $table->text('description');
            $table->string('establishment_type');
            $table->string('segments')->nullable();
            $table->string('image')->nullable();
            $table->dateTime('start_date');
            $table->dateTime('end_date');
            $table->string('venue')->nullable();
            $table->string('city');
            $table->string('uf')->nullable();
            $table->string('state')->nullable();
            $table->string('slug');
            $table->string('country')->nullable();
            $table->string('address');
            $table->string('cep');            
            $table->text('location');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_featured')->nullable();
            $table->boolean('is_published')->nullable();
            $table->boolean('is_approved')->nullable();
            $table->boolean('is_cancelled')->nullable();
            $table->integer('max_attendees')->nullable();
            $table->integer('remaining_tickets')->nullable();
            $table->json('extra_info')->nullable();
            $table->text('agenda')->nullable();
            $table->text('menu')->nullable();
            $table->text('additional_info')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('twitter_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('website')->nullable();
            $table->string('registration_link')->nullable();
            $table->string('organizer_name')->nullable();
            $table->string('organizer_email')->nullable();
            $table->string('organizer_phone')->nullable();
            $table->text('organizer_description')->nullable();
            $table->text('speaker_list')->nullable();
            $table->text('sponsor_list')->nullable();
            $table->text('partners')->nullable();
            $table->text('reviews')->nullable();
            $table->integer('rating')->nullable();
            $table->boolean('is_private')->nullable();
            $table->boolean('requires_approval')->nullable();
            $table->string('approval_message')->nullable();
            $table->timestamps();

            $table->foreign('production_id')->references('id')->on('productions')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('events');
    }
}
