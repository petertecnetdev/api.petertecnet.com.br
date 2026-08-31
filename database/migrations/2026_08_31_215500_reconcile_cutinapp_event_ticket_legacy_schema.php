<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                if (Schema::hasColumn('events', 'cep')) {
                    $table->string('cep', 255)->nullable()->change();
                }

                if (Schema::hasColumn('events', 'location')) {
                    $table->text('location')->nullable()->change();
                }
            });
        }

        if (Schema::hasTable('tickets') && ! Schema::hasColumn('tickets', 'type')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->string('type', 100)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. This migration reconciles legacy production
        // schemas with the current shared-domain contract. Restoring NOT NULL here
        // could break valid Cutinapp records that intentionally omit these fields.
    }
};
