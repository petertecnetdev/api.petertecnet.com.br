<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureEstablishmentLocationColumns();

        if (Schema::hasTable('events') && Schema::hasColumn('events', 'production_id')) {
            $this->dropForeignFor('events', 'production_id');

            Schema::table('events', function (Blueprint $table) {
                $table->renameColumn('production_id', 'establishment_id');
            });
        }

        if (Schema::hasTable('events') && Schema::hasColumn('events', 'establishment_id')) {
            $this->dropForeignFor('events', 'establishment_id');

            if (! $this->hasForeignReference('events', 'establishment_id', 'establishments')) {
                Schema::table('events', function (Blueprint $table) {
                    $table->foreign('establishment_id')
                        ->references('id')
                        ->on('establishments')
                        ->cascadeOnDelete();
                });
            }

            if (! $this->hasIndex('events', 'events_app_id_establishment_id_index')) {
                Schema::table('events', function (Blueprint $table) {
                    $table->index(['app_id', 'establishment_id']);
                });
            }
        }

        if (Schema::hasTable('follows')) {
            DB::table('follows')
                ->where('target_type', 'production')
                ->update(['target_type' => 'establishment']);
        }

        // There are no production or event records to migrate. From this point
        // Establishment(type=production) is the only persisted representation.
        Schema::dropIfExists('productions');
    }

    public function down(): void
    {
        if (! Schema::hasTable('productions')) {
            Schema::create('productions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->nullable()->index();
                $table->string('app_slug')->nullable()->index();
                $table->string('name');
                $table->string('slug')->nullable()->unique();
                $table->string('type', 100)->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('establishment_type', 100)->nullable();
                $table->text('description')->nullable();
                $table->unsignedBigInteger('city_id')->nullable()->index();
                $table->string('city', 120)->nullable();
                $table->string('uf', 2)->nullable();
                $table->string('location')->nullable();
                $table->string('cep', 20)->nullable();
                $table->string('address')->nullable();
                $table->string('address_number', 30)->nullable();
                $table->string('neighborhood', 160)->nullable();
                $table->string('address_complement', 255)->nullable();
                $table->string('address_reference', 255)->nullable();
                $table->string('formatted_address', 700)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->string('place_id', 255)->nullable();
                $table->string('google_maps_url', 2048)->nullable();
                $table->boolean('location_public')->default(false);
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('is_featured')->default(false);
                $table->boolean('is_published')->default(false);
                $table->boolean('is_approved')->default(false);
                $table->boolean('is_cancelled')->default(false);
                $table->text('additional_info')->nullable();
                $table->string('facebook_url', 2048)->nullable();
                $table->string('twitter_url', 2048)->nullable();
                $table->string('instagram_url', 2048)->nullable();
                $table->string('youtube_url', 2048)->nullable();
                $table->string('website_url', 2048)->nullable();
                $table->string('logo')->nullable();
                $table->string('background')->nullable();
                $table->json('segments')->nullable();
                $table->string('cnpj', 18)->nullable()->index();
                $table->string('fantasy')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('events') && Schema::hasColumn('events', 'establishment_id')) {
            $this->dropForeignFor('events', 'establishment_id');

            Schema::table('events', function (Blueprint $table) {
                $table->renameColumn('establishment_id', 'production_id');
            });

            Schema::table('events', function (Blueprint $table) {
                $table->foreign('production_id')
                    ->references('id')
                    ->on('productions')
                    ->cascadeOnDelete();
            });
        }

        // Do not rewrite generic establishment follows on rollback: they may
        // belong to other applications and are not safely distinguishable.
    }

    private function ensureEstablishmentLocationColumns(): void
    {
        if (! Schema::hasTable('establishments')) return;

        Schema::table('establishments', function (Blueprint $table) {
            if (! Schema::hasColumn('establishments', 'city_id')) $table->unsignedBigInteger('city_id')->nullable()->index('establishment_city_id_idx');
            if (! Schema::hasColumn('establishments', 'address_number')) $table->string('address_number', 30)->nullable();
            if (! Schema::hasColumn('establishments', 'neighborhood')) $table->string('neighborhood', 160)->nullable();
            if (! Schema::hasColumn('establishments', 'address_complement')) $table->string('address_complement', 255)->nullable();
            if (! Schema::hasColumn('establishments', 'address_reference')) $table->string('address_reference', 255)->nullable();
            if (! Schema::hasColumn('establishments', 'formatted_address')) $table->string('formatted_address', 700)->nullable();
            if (! Schema::hasColumn('establishments', 'latitude')) $table->decimal('latitude', 10, 7)->nullable();
            if (! Schema::hasColumn('establishments', 'longitude')) $table->decimal('longitude', 10, 7)->nullable();
            if (! Schema::hasColumn('establishments', 'place_id')) $table->string('place_id', 255)->nullable();
            if (! Schema::hasColumn('establishments', 'google_maps_url')) $table->string('google_maps_url', 2048)->nullable();
            if (! Schema::hasColumn('establishments', 'location_public')) $table->boolean('location_public')->default(false);
        });
    }

    private function dropForeignFor(string $table, string $column): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        $constraint = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->value('CONSTRAINT_NAME');

        if ($constraint) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                str_replace('`', '``', $table),
                str_replace('`', '``', $constraint),
            ));
        }
    }

    private function hasForeignReference(string $table, string $column, string $referencedTable): bool
    {
        if (DB::getDriverName() !== 'mysql') return false;

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->where('REFERENCED_TABLE_NAME', $referencedTable)
            ->exists();
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        if (DB::getDriverName() !== 'mysql') return false;

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }
};
