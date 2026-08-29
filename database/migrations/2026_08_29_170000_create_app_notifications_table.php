<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('type', 80)->default('general')->index();
            $table->string('title', 180);
            $table->text('message')->nullable();
            $table->string('reference_type', 80)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_url', 500)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();

            $table->index(['app_id', 'user_id', 'read_at'], 'app_notifications_unread_idx');
            $table->index(['reference_type', 'reference_id'], 'app_notifications_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
