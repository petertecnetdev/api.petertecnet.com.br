<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('client_token', 64)->nullable()->after('parent_id');
            $table->unique(['app_id', 'user_id', 'client_token'], 'social_posts_client_token_unique');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropUnique('social_posts_client_token_unique');
            $table->dropColumn('client_token');
        });
    }
};
