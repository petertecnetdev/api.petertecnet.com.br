<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('organization_media')) {
            return;
        }

        Schema::table('organization_media', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_media', 'perceptual_hash')) {
                $table->string('perceptual_hash', 64)->nullable()->after('checksum');
            }
            if (! Schema::hasColumn('organization_media', 'brightness_score')) {
                $table->unsignedTinyInteger('brightness_score')->nullable()->after('perceptual_hash');
            }
            if (! Schema::hasColumn('organization_media', 'sharpness_score')) {
                $table->unsignedTinyInteger('sharpness_score')->nullable()->after('brightness_score');
            }
            if (! Schema::hasColumn('organization_media', 'cover_score')) {
                $table->unsignedTinyInteger('cover_score')->nullable()->after('sharpness_score');
            }
            if (! Schema::hasColumn('organization_media', 'processing_version')) {
                $table->unsignedSmallInteger('processing_version')->default(1)->after('cover_score');
            }
            if (! Schema::hasColumn('organization_media', 'last_processed_at')) {
                $table->timestamp('last_processed_at')->nullable()->after('processing_version');
            }
        });

        Schema::table('organization_media', function (Blueprint $table) {
            $table->index(['app_id', 'organization_id', 'perceptual_hash'], 'org_media_perceptual_idx');
            $table->index(['app_id', 'organization_id', 'cover_score'], 'org_media_cover_score_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('organization_media')) {
            return;
        }

        Schema::table('organization_media', function (Blueprint $table) {
            foreach (['org_media_perceptual_idx', 'org_media_cover_score_idx'] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (Throwable) {
                    // Safe rollback for partially upgraded environments.
                }
            }

            $columns = array_values(array_filter([
                'perceptual_hash',
                'brightness_score',
                'sharpness_score',
                'cover_score',
                'processing_version',
                'last_processed_at',
            ], fn ($column) => Schema::hasColumn('organization_media', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
