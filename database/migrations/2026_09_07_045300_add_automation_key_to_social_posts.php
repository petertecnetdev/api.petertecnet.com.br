<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('automation_key', 160)->nullable()->after('client_token');
            $table->unique(['app_id', 'automation_key'], 'social_posts_automation_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropUnique('social_posts_automation_key_unique');
            $table->dropColumn('automation_key');
        });
    }
};
