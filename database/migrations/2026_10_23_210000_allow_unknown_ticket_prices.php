<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NULL significa valor desconhecido; zero deve representar gratuidade confirmada.
        Schema::table('tickets', function (Blueprint $table) {
            $table->decimal('price', 8, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('tickets')->whereNull('price')->exists()) {
            throw new RuntimeException('Remova ou enriqueça ingressos sem preço antes de reverter esta migration.');
        }
        Schema::table('tickets', function (Blueprint $table) {
            $table->decimal('price', 8, 2)->nullable(false)->change();
        });
    }
};
