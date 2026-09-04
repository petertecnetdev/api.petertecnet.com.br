<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('events') && Schema::hasColumn('events', 'production_id')) {
            $this->dropForeignFor('events', 'production_id');

            Schema::table('events', function (Blueprint $table) {
                $table->renameColumn('production_id', 'establishment_id');
            });
        }

        if (Schema::hasTable('events') && Schema::hasColumn('events', 'establishment_id')) {
            $this->dropForeignFor('events', 'establishment_id');

            Schema::table('events', function (Blueprint $table) {
                $table->foreign('establishment_id')
                    ->references('id')
                    ->on('establishments')
                    ->cascadeOnDelete();
                $table->index(['app_id', 'establishment_id']);
            });
        }

        if (Schema::hasTable('follows')) {
            DB::table('follows')
                ->where('target_type', 'production')
                ->update(['target_type' => 'establishment']);
        }

        // There are no production records in production. From this migration on,
        // type=production on establishments is the only persisted representation.
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
                $table->string('city', 120)->nullable();
                $table->string('uf', 2)->nullable();
                $table->string('location')->nullable();
                $table->string('cep', 20)->nullable();
                $table->string('address')->nullable();
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

        if (Schema::hasTable('follows')) {
            DB::table('follows')
                ->where('target_type', 'establishment')
                ->update(['target_type' => 'production']);
        }
    }

    private function dropForeignFor(string $table, string $column): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

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
};
