<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slug')->unique();
            $table->string('url')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('version')->nullable();
            $table->string('author')->nullable();
            $table->timestamp('release_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
