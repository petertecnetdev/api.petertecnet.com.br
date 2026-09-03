<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_deduplication_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->char('scope_hash', 64);
            $table->char('token_hash', 64);
            $table->char('request_hash', 64);
            $table->string('scope', 255);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['app_id', 'scope_hash', 'token_hash'], 'request_deduplication_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_deduplication_records');
    }
};
