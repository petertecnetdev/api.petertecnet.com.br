<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interactions', function (Blueprint $table) {
            if (! Schema::hasColumn('interactions', 'app_id')) $table->unsignedBigInteger('app_id')->nullable()->index();
            if (! Schema::hasColumn('interactions', 'type')) $table->string('type', 64)->nullable()->index();
            if (! Schema::hasColumn('interactions', 'description')) $table->string('description', 255)->nullable();
            if (! Schema::hasColumn('interactions', 'metadata')) $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('interactions', function (Blueprint $table) {
            foreach (['app_id', 'type', 'description', 'metadata'] as $column) {
                if (Schema::hasColumn('interactions', $column)) $table->dropColumn($column);
            }
        });
    }
};
