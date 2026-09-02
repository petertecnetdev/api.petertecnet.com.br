<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('oauth_clients')) {
            Schema::create('oauth_clients', function (Blueprint $table) {
                $table->id();
                $table->uuid('client_id')->unique();
                $table->foreignId('api_project_id')->constrained('api_projects')->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('secret_hash', 255);
                $table->json('scopes')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
    }
};
