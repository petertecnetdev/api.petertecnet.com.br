<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('applications')
            ->where('slug', 'locaio')
            ->update([
                'logo' => 'https://locaio.petertecnet.com.br/logo-locaio.png',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('applications')
            ->where('slug', 'locaio')
            ->update([
                'logo' => 'https://locaio.petertecnet.com.br/logo.svg',
                'updated_at' => now(),
            ]);
    }
};
