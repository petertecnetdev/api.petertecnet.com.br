<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->unsignedBigInteger('source_establishment_id')->nullable()->after('app_id');
            $table->index(['source_establishment_id', 'app_id'], 'est_source_app_idx');
            $table->foreign('source_establishment_id', 'est_source_fk')
                ->references('id')
                ->on('establishments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->dropForeign('est_source_fk');
            $table->dropIndex('est_source_app_idx');
            $table->dropColumn('source_establishment_id');
        });
    }
};
