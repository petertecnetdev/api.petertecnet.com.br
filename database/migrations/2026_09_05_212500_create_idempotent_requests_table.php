<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotent_requests', function (Blueprint $table) {
            $table->id();
            $table->string('application_key', 80);
            $table->string('actor_key', 80);
            $table->string('idempotency_key', 80);
            $table->string('route_signature', 255);
            $table->char('request_fingerprint', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('content_type', 160)->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['application_key', 'actor_key', 'idempotency_key'],
                'idempotent_requests_scope_key_unique'
            );
            $table->index(['completed_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotent_requests');
    }
};
