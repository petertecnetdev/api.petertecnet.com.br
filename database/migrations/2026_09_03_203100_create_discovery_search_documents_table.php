<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('discovery_search_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->string('title', 500);
            $table->text('summary')->nullable();
            $table->text('search_text');
            $table->string('category', 180)->nullable();
            $table->string('city', 180)->nullable();
            $table->string('url', 1000);
            $table->unsignedSmallInteger('boost')->default(10);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['document_type', 'document_id']);
            $table->index(['application_id', 'document_type']);
            $table->index(['category', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_search_documents');
    }
};
