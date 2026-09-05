<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('access_token_hash', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained('establishments')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name', 160)->nullable();
            $table->string('requester_email', 190)->nullable();
            $table->string('requester_phone', 40)->nullable();
            $table->string('subject', 200);
            $table->string('category', 40)->default('general');
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('open');
            $table->string('channel', 30)->default('web');
            $table->text('source_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority', 'last_message_at'], 'support_tickets_queue_idx');
            $table->index(['application_id', 'status'], 'support_tickets_app_status_idx');
            $table->index(['assigned_to_user_id', 'status'], 'support_tickets_assignee_status_idx');
            $table->index(['requester_email', 'created_at'], 'support_tickets_requester_idx');
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_type', 30)->default('requester');
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['support_ticket_id', 'is_internal', 'created_at'], 'support_messages_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
