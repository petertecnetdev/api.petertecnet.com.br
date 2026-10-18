<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table): void {
            if (! Schema::hasColumn('tickets', 'curatorial_information_only')) {
                $table->boolean('curatorial_information_only')->default(false)->index();
            }
            if (! Schema::hasColumn('tickets', 'curatorial_source_url')) {
                $table->string('curatorial_source_url', 2048)->nullable();
            }
            if (! Schema::hasColumn('tickets', 'curatorial_price_observed_at')) {
                $table->date('curatorial_price_observed_at')->nullable();
            }
            if (! Schema::hasColumn('tickets', 'curatorial_bundle_size')) {
                $table->unsignedSmallInteger('curatorial_bundle_size')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Rollback conservador: dados e bloqueio comercial devem ser preservados.
    }
};
