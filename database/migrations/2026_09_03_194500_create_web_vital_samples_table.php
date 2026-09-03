<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_vital_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('session_id', 80)->nullable()->index();
            $table->string('metric_name', 12)->index();
            $table->decimal('metric_value', 14, 3);
            $table->string('rating', 16)->nullable()->index();
            $table->string('path', 500)->nullable()->index();
            $table->string('device_class', 24)->nullable()->index();
            $table->string('connection_type', 24)->nullable();
            $table->string('navigation_type', 32)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['metric_name', 'occurred_at']);
            $table->index(['application_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_vital_samples');
    }
};
