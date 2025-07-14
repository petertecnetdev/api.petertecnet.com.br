<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')
                ->constrained('applications')
                ->onDelete('cascade');

            $table->string('entity_name', 255);
            $table->unsignedBigInteger('entity_id');

            $table->string('order_number', 10)->unique();
            $table->timestamp('order_datetime');

            $table->foreignId('attendant_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->foreignId('client_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->string('customer_name', 255);
            $table->string('customer_phone', 20)->nullable();
            $table->string('access_code', 20);

            $table->string('origin', 20);
            $table->enum('fulfillment', ['dine-in', 'take-away', 'delivery'])
                ->default('dine-in');
            $table->enum('payment_status', ['pending', 'paid', 'failed'])
                ->default('pending');

            $table->string('payment_method', 50);
            $table->decimal('total_price', 10, 2);
            $table->string('status', 50);
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('orders');
    }
}
