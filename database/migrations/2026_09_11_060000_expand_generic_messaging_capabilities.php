<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('conversation_participants', 'pinned_at')) {
                $table->timestamp('pinned_at')->nullable()->after('muted_until')->index();
            }
            if (! Schema::hasColumn('conversation_participants', 'marked_unread_at')) {
                $table->timestamp('marked_unread_at')->nullable()->after('last_read_at')->index();
            }
        });

        if (! Schema::hasTable('message_receipts')) {
            Schema::create('message_receipts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('message_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->unique(['message_id', 'user_id'], 'message_receipt_unique');
                $table->index(['user_id', 'read_at'], 'message_receipt_user_read_idx');
                $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('message_reactions')) {
            Schema::create('message_reactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('message_id');
                $table->unsignedBigInteger('user_id');
                $table->string('reaction', 24);
                $table->timestamps();
                $table->unique(['message_id', 'user_id', 'reaction'], 'message_reaction_unique');
                $table->index(['message_id', 'reaction'], 'message_reaction_message_idx');
                $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('message_attachments')) {
            Schema::create('message_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('message_id');
                $table->string('kind', 24)->default('file');
                $table->string('disk', 32)->default('public');
                $table->string('path', 500);
                $table->string('original_name', 255)->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['message_id', 'kind'], 'message_attachment_message_idx');
                $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('message_reactions');
        Schema::dropIfExists('message_receipts');

        Schema::table('conversation_participants', function (Blueprint $table) {
            if (Schema::hasColumn('conversation_participants', 'pinned_at')) {
                $table->dropColumn('pinned_at');
            }
            if (Schema::hasColumn('conversation_participants', 'marked_unread_at')) {
                $table->dropColumn('marked_unread_at');
            }
        });
    }
};
