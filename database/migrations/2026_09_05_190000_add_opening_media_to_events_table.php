<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasType = Schema::hasColumn('events', 'opening_media_type');
        $hasMedia = Schema::hasColumn('events', 'opening_media');

        if ($hasType && $hasMedia) {
            return;
        }

        Schema::table('events', function (Blueprint $table) use ($hasType, $hasMedia) {
            if (! $hasType) {
                $table->string('opening_media_type', 20)->default('banner')->after('image');
            }

            if (! $hasMedia) {
                $table->text('opening_media')->nullable()->after('opening_media_type');
            }
        });
    }

    public function down(): void
    {
        $columns = [];

        if (Schema::hasColumn('events', 'opening_media')) {
            $columns[] = 'opening_media';
        }

        if (Schema::hasColumn('events', 'opening_media_type')) {
            $columns[] = 'opening_media_type';
        }

        if ($columns === []) {
            return;
        }

        Schema::table('events', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
