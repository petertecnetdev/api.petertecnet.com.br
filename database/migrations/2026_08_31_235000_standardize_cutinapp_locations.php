<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('brazilian_municipalities')) {
            Schema::create('brazilian_municipalities', function (Blueprint $table) {
                $table->unsignedBigInteger('ibge_code')->primary();
                $table->string('name', 120);
                $table->string('normalized_name', 120);
                $table->char('uf', 2);
                $table->string('slug', 140);
                $table->timestamps();
                $table->unique(['uf', 'normalized_name'], 'municipality_uf_name_uq');
                $table->index(['uf', 'name'], 'municipality_uf_name_idx');
                $table->index('slug', 'municipality_slug_idx');
            });
        }

        Schema::table('productions', function (Blueprint $table) {
            if (! Schema::hasColumn('productions', 'city_id')) $table->unsignedBigInteger('city_id')->nullable()->index('prod_city_id_idx');
            if (! Schema::hasColumn('productions', 'address_number')) $table->string('address_number', 30)->nullable();
            if (! Schema::hasColumn('productions', 'neighborhood')) $table->string('neighborhood', 160)->nullable();
            if (! Schema::hasColumn('productions', 'address_complement')) $table->string('address_complement', 255)->nullable();
            if (! Schema::hasColumn('productions', 'address_reference')) $table->string('address_reference', 255)->nullable();
            if (! Schema::hasColumn('productions', 'formatted_address')) $table->string('formatted_address', 700)->nullable();
            if (! Schema::hasColumn('productions', 'latitude')) $table->decimal('latitude', 10, 7)->nullable();
            if (! Schema::hasColumn('productions', 'longitude')) $table->decimal('longitude', 10, 7)->nullable();
            if (! Schema::hasColumn('productions', 'place_id')) $table->string('place_id', 255)->nullable();
            if (! Schema::hasColumn('productions', 'google_maps_url')) $table->string('google_maps_url', 2048)->nullable();
            if (! Schema::hasColumn('productions', 'location_public')) $table->boolean('location_public')->default(false);
        });

        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'city_id')) $table->unsignedBigInteger('city_id')->nullable()->index('event_city_id_idx');
            if (! Schema::hasColumn('events', 'event_format')) $table->string('event_format', 20)->default('in_person')->index('event_format_idx');
            if (! Schema::hasColumn('events', 'address_number')) $table->string('address_number', 30)->nullable();
            if (! Schema::hasColumn('events', 'neighborhood')) $table->string('neighborhood', 160)->nullable();
            if (! Schema::hasColumn('events', 'address_complement')) $table->string('address_complement', 255)->nullable();
            if (! Schema::hasColumn('events', 'address_reference')) $table->string('address_reference', 255)->nullable();
            if (! Schema::hasColumn('events', 'formatted_address')) $table->string('formatted_address', 700)->nullable();
            if (! Schema::hasColumn('events', 'place_id')) $table->string('place_id', 255)->nullable();
            if (! Schema::hasColumn('events', 'online_platform')) $table->string('online_platform', 120)->nullable();
            if (! Schema::hasColumn('events', 'online_url')) $table->string('online_url', 2048)->nullable();
            if (! Schema::hasColumn('events', 'online_instructions')) $table->text('online_instructions')->nullable();
        });
    }

    public function down(): void
    {
        // Non-destructive: location data may already be referenced by production records.
    }
};
