<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('username', 191)->nullable();
            $table->string('display_name', 191)->nullable();
            $table->text('avatar_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_authenticated_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id'], 'user_social_provider_identity_unique');
            $table->index(['user_id', 'provider'], 'user_social_user_provider_index');
        });

        Schema::create('social_auth_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('username', 191)->nullable();
            $table->string('display_name', 191)->nullable();
            $table->text('avatar_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'provider_user_id'], 'social_auth_challenge_identity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_auth_challenges');
        Schema::dropIfExists('user_social_accounts');
    }
};
