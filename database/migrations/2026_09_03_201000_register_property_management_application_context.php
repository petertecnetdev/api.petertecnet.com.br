<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('applications')->updateOrInsert(
            ['slug' => 'locaio'],
            [
                'name' => 'Locaio',
                'description' => 'Gestão de imóveis, locações, contratos, documentos e cobranças.',
                'url' => 'https://locaio.petertecnet.com.br',
                'is_active' => true,
                'version' => '1.0.0',
                'author' => 'Peter Tecnet',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('applications')->where('slug', 'locaio')->delete();
    }
};
