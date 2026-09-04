<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SLUG = 'petrinia-cutinapp-persistencia-tecnologia';

    public function up(): void
    {
        if (! Schema::hasTable('content_entries')) {
            return;
        }

        DB::table('content_entries')
            ->where('type', 'article')
            ->where('slug', self::SLUG)
            ->update([
                'og_image' => 'https://cutinapp.petertecnet.com.br/images/cutinapp.png',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_entries')) {
            return;
        }

        DB::table('content_entries')
            ->where('type', 'article')
            ->where('slug', self::SLUG)
            ->update([
                'og_image' => 'https://petertecnet.com.br/blog/petrinia-cutinapp-cover.svg',
                'updated_at' => now(),
            ]);
    }
};
