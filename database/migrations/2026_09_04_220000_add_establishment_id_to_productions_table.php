<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->foreignId('establishment_id')
                ->nullable()
                ->after('app_id')
                ->constrained('establishments')
                ->nullOnDelete();
            $table->unique('establishment_id', 'productions_establishment_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->dropForeign(['establishment_id']);
            $table->dropUnique('productions_establishment_id_unique');
            $table->dropColumn('establishment_id');
        });
    }
};
