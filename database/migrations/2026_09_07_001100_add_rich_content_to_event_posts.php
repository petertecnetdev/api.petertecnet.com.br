<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_posts', function (Blueprint $table) {
            $table->string('post_type', 24)->default('text')->after('body');
            $table->json('media')->nullable()->after('post_type');
            $table->json('poll')->nullable()->after('media');
        });

        Schema::create('event_post_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id');
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('option_id');
            $table->timestamps();

            $table->unique(['app_id', 'post_id', 'user_id'], 'event_poll_vote_user_unique');
            $table->index(['app_id', 'post_id', 'option_id'], 'event_poll_vote_option_idx');
            $table->foreign('post_id')->references('id')->on('event_posts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_post_poll_votes');

        Schema::table('event_posts', function (Blueprint $table) {
            $table->dropColumn(['post_type', 'media', 'poll']);
        });
    }
};
