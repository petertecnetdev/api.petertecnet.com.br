<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_conversation_participants', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('last_read_at')->index();
            $table->timestamp('muted_until')->nullable()->after('archived_at');
            $table->timestamp('pinned_at')->nullable()->after('muted_until')->index();
        });

        Schema::table('app_messages', function (Blueprint $table) {
            $table->foreignId('reply_to_message_id')->nullable()->after('sender_user_id')->constrained('app_messages')->nullOnDelete();
            $table->string('client_token', 64)->nullable()->after('type');
            $table->unique(['conversation_id', 'client_token'], 'app_messages_client_token_unique');
        });

        Schema::create('app_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('message_id')->constrained('app_messages')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('original_name', 255);
            $table->string('extension', 20)->nullable();
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path', 500);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['message_id', 'kind'], 'app_message_attachments_message_kind_idx');
        });

        Schema::create('app_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('app_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 24);
            $table->timestamps();

            $table->unique(['message_id', 'user_id'], 'app_message_reactions_user_unique');
            $table->index(['message_id', 'emoji'], 'app_message_reactions_message_emoji_idx');
        });

        Schema::create('app_messaging_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('blocker_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['application_id', 'blocker_user_id', 'blocked_user_id'], 'app_messaging_blocks_unique');
            $table->index(['application_id', 'blocked_user_id'], 'app_messaging_blocks_blocked_idx');
        });

        Schema::create('app_messaging_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('reporter_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reported_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('app_conversations')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('app_messages')->nullOnDelete();
            $table->string('reason', 40);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->timestamps();

            $table->index(['application_id', 'reported_user_id', 'status'], 'app_messaging_reports_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_messaging_reports');
        Schema::dropIfExists('app_messaging_blocks');
        Schema::dropIfExists('app_message_reactions');
        Schema::dropIfExists('app_message_attachments');

        Schema::table('app_messages', function (Blueprint $table) {
            $table->dropUnique('app_messages_client_token_unique');
            $table->dropConstrainedForeignId('reply_to_message_id');
            $table->dropColumn('client_token');
        });

        Schema::table('app_conversation_participants', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'muted_until', 'pinned_at']);
        });
    }
};
