<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('organization_media_albums')) {
            Schema::create('organization_media_albums', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('name', 100);
                $table->string('slug', 120);
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();
                $table->unique(['app_id', 'organization_id', 'slug'], 'org_media_album_slug_unique');
                $table->index(['app_id', 'organization_id', 'position'], 'org_media_album_scope_idx');
            });
        }

        if (! Schema::hasTable('organization_media')) {
            return;
        }

        Schema::table('organization_media', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_media', 'album_id')) {
                $table->unsignedBigInteger('album_id')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('organization_media', 'original_path')) {
                $table->string('original_path', 1024)->nullable()->after('path');
            }
            if (! Schema::hasColumn('organization_media', 'thumbnail_path')) {
                $table->string('thumbnail_path', 1024)->nullable()->after('original_path');
            }
            if (! Schema::hasColumn('organization_media', 'alt_text')) {
                $table->string('alt_text', 255)->nullable()->after('caption');
            }
            if (! Schema::hasColumn('organization_media', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('alt_text');
            }
            if (! Schema::hasColumn('organization_media', 'status')) {
                $table->string('status', 24)->default('published')->after('is_featured');
            }
            if (! Schema::hasColumn('organization_media', 'original_name')) {
                $table->string('original_name', 255)->nullable()->after('status');
            }
            if (! Schema::hasColumn('organization_media', 'mime_type')) {
                $table->string('mime_type', 100)->nullable()->after('original_name');
            }
            if (! Schema::hasColumn('organization_media', 'file_size')) {
                $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            }
            if (! Schema::hasColumn('organization_media', 'width')) {
                $table->unsignedInteger('width')->nullable()->after('file_size');
            }
            if (! Schema::hasColumn('organization_media', 'height')) {
                $table->unsignedInteger('height')->nullable()->after('width');
            }
            if (! Schema::hasColumn('organization_media', 'checksum')) {
                $table->char('checksum', 64)->nullable()->after('height');
            }
            if (! Schema::hasColumn('organization_media', 'focal_x')) {
                $table->unsignedTinyInteger('focal_x')->default(50)->after('checksum');
            }
            if (! Schema::hasColumn('organization_media', 'focal_y')) {
                $table->unsignedTinyInteger('focal_y')->default(50)->after('focal_x');
            }
            if (! Schema::hasColumn('organization_media', 'rotation')) {
                $table->unsignedSmallInteger('rotation')->default(0)->after('focal_y');
            }
            if (! Schema::hasColumn('organization_media', 'updated_by_user_id')) {
                $table->unsignedBigInteger('updated_by_user_id')->nullable()->after('position');
            }
            if (! Schema::hasColumn('organization_media', 'deleted_by_user_id')) {
                $table->unsignedBigInteger('deleted_by_user_id')->nullable()->after('updated_by_user_id');
            }
            if (! Schema::hasColumn('organization_media', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('deleted_by_user_id');
            }
        });

        Schema::table('organization_media', function (Blueprint $table) {
            $table->index(['app_id', 'organization_id', 'deleted_at', 'position'], 'org_media_active_scope_idx');
            $table->index(['app_id', 'organization_id', 'checksum'], 'org_media_checksum_idx');
            $table->index(['app_id', 'organization_id', 'is_featured'], 'org_media_featured_idx');
            $table->index(['album_id', 'position'], 'org_media_album_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('organization_media')) {
            Schema::table('organization_media', function (Blueprint $table) {
                foreach ([
                    'org_media_active_scope_idx',
                    'org_media_checksum_idx',
                    'org_media_featured_idx',
                    'org_media_album_idx',
                ] as $index) {
                    try {
                        $table->dropIndex($index);
                    } catch (Throwable) {
                        // Migration remains safe across partially upgraded environments.
                    }
                }

                $columns = array_values(array_filter([
                    'album_id',
                    'original_path',
                    'thumbnail_path',
                    'alt_text',
                    'is_featured',
                    'status',
                    'original_name',
                    'mime_type',
                    'file_size',
                    'width',
                    'height',
                    'checksum',
                    'focal_x',
                    'focal_y',
                    'rotation',
                    'updated_by_user_id',
                    'deleted_by_user_id',
                    'deleted_at',
                ], fn ($column) => Schema::hasColumn('organization_media', $column)));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        Schema::dropIfExists('organization_media_albums');
    }
};
