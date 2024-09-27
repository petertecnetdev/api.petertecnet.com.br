<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id(); // BIGINT(20) UNSIGNED AUTO_INCREMENT
            $table->string('name')->collate('utf8mb4_unicode_ci'); // Nome do aplicativo
            $table->string('description')->nullable()->collate('utf8mb4_unicode_ci'); // Descrição do aplicativo
            $table->string('slug')->unique()->collate('utf8mb4_unicode_ci'); // Slug único para SEO
            $table->string('url')->nullable()->collate('utf8mb4_unicode_ci'); // URL do aplicativo
            $table->string('logo')->nullable()->collate('utf8mb4_unicode_ci'); // URL do logo do aplicativo
            $table->boolean('is_active')->default(true); // Status ativo/inativo do aplicativo
            $table->string('version')->nullable()->collate('utf8mb4_unicode_ci'); // Versão atual do aplicativo
            $table->string('author')->nullable()->collate('utf8mb4_unicode_ci'); // Autor ou desenvolvedor do aplicativo
            $table->timestamp('release_date')->nullable(); // Data de lançamento do aplicativo
            $table->timestamps(); // created_at e updated_at
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('applications');
    }
};
