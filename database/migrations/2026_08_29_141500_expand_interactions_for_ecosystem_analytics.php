<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interactions', function (Blueprint $table) {
            $table->unsignedBigInteger('app_id')->nullable()->after('user_id');
            $table->string('route', 255)->nullable()->after('interaction_type');
            $table->string('method', 12)->nullable()->after('route');
            $table->string('session_key', 120)->nullable()->after('method');
            $table->index(['user_id', 'created_at'], 'interactions_user_created_idx');
            $table->index(['app_id', 'created_at'], 'interactions_app_created_idx');
            $table->index(['interaction_type', 'created_at'], 'interactions_type_created_idx');
            $table->index(['entity_type', 'entity_id'], 'interactions_entity_idx');
            $table->foreign('app_id')->references('id')->on('applications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('interactions', function (Blueprint $table) {
            $table->dropForeign(['app_id']);
            $table->dropIndex('interactions_user_created_idx');
            $table->dropIndex('interactions_app_created_idx');
            $table->dropIndex('interactions_type_created_idx');
            $table->dropIndex('interactions_entity_idx');
            $table->dropColumn(['app_id', 'route', 'method', 'session_key']);
        });
    }
};
