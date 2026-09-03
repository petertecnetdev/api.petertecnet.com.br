<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('namespace', 120);
            $table->string('key', 160);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'namespace', 'key']);
            $table->index(['namespace', 'key']);
        });

        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 120);
            $table->string('name', 160);
            $table->json('configuration');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'scope', 'name']);
            $table->index(['user_id', 'scope', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
        Schema::dropIfExists('user_preferences');
    }
};
