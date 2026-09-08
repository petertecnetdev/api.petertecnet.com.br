<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 20)->default('offer');
            $table->string('origin_city', 120);
            $table->string('origin_region', 160)->nullable();
            $table->string('origin_label', 200)->nullable();
            $table->string('meeting_point', 255)->nullable();
            $table->dateTime('departure_at');
            $table->unsignedTinyInteger('seats')->default(1);
            $table->string('cost_type', 24)->default('free');
            $table->decimal('suggested_cost', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('open');
            $table->timestamps();

            $table->index(['app_id', 'event_id', 'status']);
            $table->index(['event_id', 'kind', 'departure_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('ride_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('seats')->default(1);
            $table->string('message', 500)->nullable();
            $table->string('status', 24)->default('pending');
            $table->dateTime('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['ride_id', 'user_id']);
            $table->index(['ride_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_requests');
        Schema::dropIfExists('rides');
    }
};
