<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('type', 24)->default('direct');
            $table->string('direct_key', 100)->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['application_id', 'direct_key'], 'app_conversations_direct_unique');
            $table->index(['application_id', 'last_message_at'], 'app_conversations_app_last_idx');
        });

        Schema::create('app_conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('app_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id'], 'app_conversation_participant_unique');
            $table->index(['user_id', 'unread_count'], 'app_conversation_participant_unread_idx');
        });

        Schema::create('app_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('app_conversations')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 24)->default('text');
            $table->text('body');
            $table->json('metadata')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id'], 'app_messages_conversation_id_idx');
            $table->index(['sender_user_id', 'created_at'], 'app_messages_sender_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_messages');
        Schema::dropIfExists('app_conversation_participants');
        Schema::dropIfExists('app_conversations');
    }
};
