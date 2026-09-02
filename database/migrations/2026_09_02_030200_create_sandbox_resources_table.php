<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('sandbox_resources')) {
            Schema::create('sandbox_resources', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('api_project_id')->constrained('api_projects')->cascadeOnDelete();
                $table->string('resource_type', 120)->index();
                $table->json('payload');
                $table->timestamps();
                $table->index(['api_project_id', 'resource_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sandbox_resources');
    }
};
