<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id(); // bigint unsigned PK
            $table->string('name');
            $table->json('permissions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
