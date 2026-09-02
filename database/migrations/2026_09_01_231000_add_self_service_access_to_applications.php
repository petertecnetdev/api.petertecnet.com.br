<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('applications', 'self_service_access')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->boolean('self_service_access')->default(false)->after('is_active');
            });
        }

        DB::table('applications')->updateOrInsert(
            ['slug' => 'laora'],
            [
                'name' => 'Laora',
                'description' => 'Conexões reais, matches transparentes e conversas com segurança.',
                'url' => 'https://laora.petertecnet.com.br',
                'is_active' => true,
                'self_service_access' => true,
                'version' => '1.0.0',
                'author' => 'Peter Tecnet',
                'release_date' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('applications')->where('slug', 'laora')->delete();
        if (Schema::hasColumn('applications', 'self_service_access')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->dropColumn('self_service_access');
            });
        }
    }
};
