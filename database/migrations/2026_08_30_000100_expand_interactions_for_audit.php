<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interactions', function (Blueprint $table) {
            $table->string('request_id', 100)->nullable()->index()->after('session_key');
            $table->string('correlation_id', 100)->nullable()->index()->after('request_id');
            $table->unsignedBigInteger('parent_interaction_id')->nullable()->index()->after('correlation_id');
            $table->string('outcome', 20)->default('success')->index()->after('interaction_type');
            $table->string('severity', 20)->default('normal')->index()->after('outcome');
            $table->string('environment', 30)->nullable()->index()->after('severity');
        });
    }

    public function down(): void
    {
        Schema::table('interactions', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropIndex(['correlation_id']);
            $table->dropIndex(['parent_interaction_id']);
            $table->dropIndex(['outcome']);
            $table->dropIndex(['severity']);
            $table->dropIndex(['environment']);
            $table->dropColumn(['request_id', 'correlation_id', 'parent_interaction_id', 'outcome', 'severity', 'environment']);
        });
    }
};
