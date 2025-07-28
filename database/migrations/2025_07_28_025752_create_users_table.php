<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->string('user_name')->nullable();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('verification_code')->nullable();
            $table->string('avatar')->nullable();
            $table->string('reset_password_code')->nullable();
            $table->timestamp('reset_password_expires_at')->nullable();
            $table->string('remember_token')->nullable();

            $table->unsignedBigInteger('profile_id')->nullable();
            $table->foreign('profile_id')->references('id')->on('profiles')->nullOnDelete();

            $table->string('cpf')->nullable();
            $table->string('google_id')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('postal_code')->nullable();
            $table->date('birthdate')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('marital_status')->nullable();
            $table->string('occupation')->nullable();
            $table->text('about')->nullable();
            $table->string('favorite_artist')->nullable();
            $table->string('favorite_genre')->nullable();
            $table->string('payment_method')->nullable();

            $table->boolean('newsletter_subscription')->default(false);
            $table->integer('ticket_purchases')->default(0);
            $table->decimal('account_balance', 10, 2)->default(0.00);

            $table->boolean('is_producer')->default(false);
            $table->boolean('is_participant')->default(false);
            $table->boolean('is_promoter')->default(false);
            $table->boolean('is_barber')->default(false);
            $table->boolean('is_barbershoper')->default(false);
            $table->boolean('is_partner')->default(false);
            $table->boolean('is_ticket_seller')->default(false);

            $table->json('extra_info')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
