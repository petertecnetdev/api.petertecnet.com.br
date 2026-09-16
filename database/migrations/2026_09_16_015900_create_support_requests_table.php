<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 50)->default('general');
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('open');
            $table->string('subject', 180)->nullable();
            $table->text('description');
            $table->string('route', 1000)->nullable();
            $table->string('app_version', 80)->nullable();
            $table->string('device', 255)->nullable();
            $table->string('correlation_id', 100)->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'status', 'priority']);
            $table->index(['application_id', 'category', 'created_at']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
    }
};
