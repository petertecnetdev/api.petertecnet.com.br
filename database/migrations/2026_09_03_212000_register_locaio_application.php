<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $values = [
            'name' => 'Locaio',
            'description' => 'Gestão de imóveis, locações, contratos, documentos, cobranças e obrigações recorrentes.',
            'url' => 'https://locaio.petertecnet.com.br',
            'logo' => 'https://locaio.petertecnet.com.br/logo-locaio.png',
            'is_active' => true,
            'version' => '0.1.0',
            'author' => 'Peter Tecnet',
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('applications', 'self_service_access')) $values['self_service_access'] = true;
        if (Schema::hasColumn('applications', 'capabilities')) {
            $values['capabilities'] = json_encode([
                'leasing', 'payments', 'notifications', 'locations',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (Schema::hasColumn('applications', 'launcher_order')) $values['launcher_order'] = 80;
        if (Schema::hasColumn('applications', 'category')) $values['category'] = 'business';
        if (Schema::hasColumn('applications', 'is_visible')) $values['is_visible'] = true;
        if (Schema::hasColumn('applications', 'is_default')) $values['is_default'] = false;
        if (Schema::hasColumn('applications', 'operational_status')) $values['operational_status'] = 'operational';
        if (Schema::hasColumn('applications', 'ecosystem_sdk_version')) $values['ecosystem_sdk_version'] = '1';

        $existing = DB::table('applications')->where('slug', 'locaio')->first();
        if ($existing) {
            DB::table('applications')->where('id', $existing->id)->update($values);
            return;
        }

        DB::table('applications')->insert(array_merge($values, [
            'slug' => 'locaio',
            'release_date' => $now,
            'created_at' => $now,
        ]));
    }

    public function down(): void
    {
        DB::table('applications')->where('slug', 'locaio')->delete();
    }
};
