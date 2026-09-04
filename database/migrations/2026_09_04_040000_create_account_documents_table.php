<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 64)->index();
            $table->string('side', 16)->nullable();
            $table->string('label', 180)->nullable();
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->string('extension', 16)->nullable();
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path', 700);
            $table->string('status', 32)->default('pending')->index();
            $table->date('expires_on')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_downloaded_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'category', 'created_at'], 'account_documents_user_category_idx');
            $table->index(['user_id', 'sha256'], 'account_documents_user_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_documents');
    }
};
