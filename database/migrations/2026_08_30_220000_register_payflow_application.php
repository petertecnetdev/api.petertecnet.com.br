<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        $existing = DB::table('applications')->where('slug', 'payflow')->first();

        if ($existing) {
            DB::table('applications')->where('id', $existing->id)->update([
                'name' => 'Peter PayFlow',
                'description' => 'Atendimento, vendas, propostas, cobranças e follow-up inteligente para empresas.',
                'url' => 'https://payflow.petertecnet.com.br',
                'is_active' => true,
                'version' => '0.1.0',
                'author' => 'Peter Tecnet',
                'updated_at' => $now,
            ]);
            return;
        }

        DB::table('applications')->insert([
            'name' => 'Peter PayFlow',
            'description' => 'Atendimento, vendas, propostas, cobranças e follow-up inteligente para empresas.',
            'slug' => 'payflow',
            'url' => 'https://payflow.petertecnet.com.br',
            'logo' => 'https://payflow.petertecnet.com.br/logo',
            'is_active' => true,
            'version' => '0.1.0',
            'author' => 'Peter Tecnet',
            'release_date' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('applications')->where('slug', 'payflow')->delete();
    }
};
