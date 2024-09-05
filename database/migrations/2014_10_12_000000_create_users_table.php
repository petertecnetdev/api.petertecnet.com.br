<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('user_name')->unique();
            $table->string('last_name')->nullable();
            $table->string('verification_code')->nullable();
            $table->string('reset_password_code')->nullable();
            $table->timestamp('reset_password_expires_at')->nullable();
            $table->string('avatar')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();

            // Other attributes
            $table->string('cpf')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->string('uf')->nullable();
            $table->string('postal_code')->nullable();
            $table->date('birthdate')->nullable();
            $table->string('gender')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('occupation')->nullable();
            $table->text('about')->nullable();
            $table->string('favorite_artist')->nullable();
            $table->string('favorite_genre')->nullable();
            $table->string('payment_method')->nullable();
            $table->boolean('newsletter_subscription')->default(true);
            $table->integer('ticket_purchases')->nullable();
            $table->decimal('account_balance', 10, 2)->nullable();
            $table->boolean('is_producer')->nullable();
            $table->boolean('is_participant')->nullable();
            $table->boolean('is_promoter')->nullable();
            $table->boolean('is_partner')->nullable();
            $table->boolean('is_ticket_seller')->nullable();
            $table->json('extra_info')->nullable();
            $table->unsignedBigInteger('profile_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users');
    }
}