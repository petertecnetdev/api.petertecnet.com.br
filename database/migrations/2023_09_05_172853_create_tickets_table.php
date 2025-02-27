<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('event_id');
            $table->string('ticket_type');            
            $table->text('description')->nullable();
            $table->dateTime('limit_date')->nullable();
            $table->decimal('price', 8, 2);
            $table->integer('quantity');
            $table->json('extras')->nullable();
            $table->timestamps();

            // Foreign key constraint to link tickets to events
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tickets');
    }
};
