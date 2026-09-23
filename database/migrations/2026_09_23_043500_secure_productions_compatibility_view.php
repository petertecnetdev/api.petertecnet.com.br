<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('establishments')) {
            return;
        }

        $this->createInvokerView();
    }

    public function down(): void
    {
        // Deliberately keep SQL SECURITY INVOKER on rollback. Reintroducing a
        // host-specific DEFINER makes the compatibility view fail after VPS or
        // database-user migrations when that definer account is not present.
    }

    private function createInvokerView(): void
    {
        DB::statement(<<<'SQL'
CREATE OR REPLACE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW productions AS
SELECT
    id, app_id, name, fantasy, cnpj, type, slug, establishment_type, phone, segments,
    description, location, cep, address, city, uf, country, logo, background, capacity,
    user_id, start_date, end_date, is_featured, contact_email, contact_phone, is_published,
    is_approved, ticket_price_min, ticket_price_max, total_tickets_sold, total_tickets_available,
    is_cancelled, additional_info, facebook_url, website_url, twitter_url, instagram_url,
    youtube_url, other_information, images, created_at, updated_at, app_slug, city_id,
    address_number, neighborhood, address_complement, address_reference, formatted_address,
    latitude, longitude, place_id, google_maps_url, location_public, deleted_at
FROM establishments
WHERE category = 'production'
SQL);
    }
};
