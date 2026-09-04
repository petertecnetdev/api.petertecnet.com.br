<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $productionForeignKeys = [
        'acquisition_referrals' => 'acquisition_referrals_production_id_foreign',
        'commerce_orders' => 'cutinapp_orders_production_id_foreign',
        'contract_acceptances' => 'cutinapp_producer_contract_acceptances_production_id_foreign',
        'ecosystem_payments' => 'ecosystem_payments_production_id_foreign',
        'events' => 'events_production_id_foreign',
        'financial_ledger_entries' => 'financial_ledger_entries_production_id_foreign',
        'ledger_entries' => 'cutinapp_ledger_entries_production_id_foreign',
        'menus' => 'menus_production_id_foreign',
        'merchant_payment_accounts' => 'cutinapp_producer_payment_accounts_production_id_foreign',
        'payout_requests' => 'cutinapp_payout_requests_production_id_foreign',
    ];

    private array $nullableProductionForeignKeys = [
        'acquisition_referrals',
        'ecosystem_payments',
        'financial_ledger_entries',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('productions')) {
            return;
        }

        $this->extendEstablishments();
        $this->dropForeignKeysToLegacyProductions();

        $mapping = [];
        foreach (DB::table('productions')->orderBy('id')->get() as $production) {
            $establishmentId = $this->resolveEstablishmentId($production);
            $slug = $this->uniqueEstablishmentSlug((string) ($production->slug ?: $production->name), $establishmentId, (int) $production->id);

            $payload = [
                'app_id' => $production->app_id,
                'app_slug' => $production->app_slug,
                'legacy_production_id' => $production->id,
                'name' => $production->name,
                'fantasy' => $production->fantasy ?: $production->name,
                'slug' => $slug,
                'cnpj' => $production->cnpj,
                'type' => $production->type ?: 'production',
                'category' => 'production',
                'establishment_type' => $production->establishment_type ?: 'production',
                'phone' => $production->phone ?: $production->contact_phone,
                'email' => $production->contact_email,
                'description' => $production->description,
                'additional_info' => $production->additional_info,
                'city' => $production->city,
                'uf' => $production->uf,
                'location' => $production->location,
                'cep' => $production->cep,
                'address' => $production->address,
                'country' => $production->country,
                'capacity' => $production->capacity,
                'start_date' => $production->start_date,
                'end_date' => $production->end_date,
                'contact_email' => $production->contact_email,
                'contact_phone' => $production->contact_phone,
                'user_id' => $production->user_id,
                'created_by' => $production->user_id,
                'updated_by' => $production->user_id,
                'logo' => $production->logo,
                'background' => $production->background,
                'is_featured' => (bool) $production->is_featured,
                'is_published' => (bool) $production->is_published,
                'is_approved' => (bool) $production->is_approved,
                'is_cancelled' => (bool) $production->is_cancelled,
                'website_url' => $production->website_url,
                'facebook_url' => $production->facebook_url,
                'instagram_url' => $production->instagram_url,
                'twitter_url' => $production->twitter_url,
                'youtube_url' => $production->youtube_url,
                'segments' => $production->segments,
                'ticket_price_min' => $production->ticket_price_min,
                'ticket_price_max' => $production->ticket_price_max,
                'total_tickets_sold' => $production->total_tickets_sold,
                'total_tickets_available' => $production->total_tickets_available,
                'other_information' => $production->other_information,
                'images' => $production->images,
                'city_id' => $production->city_id,
                'address_number' => $production->address_number,
                'neighborhood' => $production->neighborhood,
                'address_complement' => $production->address_complement,
                'address_reference' => $production->address_reference,
                'formatted_address' => $production->formatted_address,
                'latitude' => $production->latitude,
                'longitude' => $production->longitude,
                'place_id' => $production->place_id,
                'google_maps_url' => $production->google_maps_url,
                'location_public' => (bool) $production->location_public,
                'created_at' => $production->created_at,
                'updated_at' => $production->updated_at,
                'deleted_at' => $production->deleted_at,
            ];

            if ($establishmentId) {
                DB::table('establishments')->where('id', $establishmentId)->update($payload);
            } else {
                $establishmentId = DB::table('establishments')->insertGetId($payload);
            }

            $mapping[(int) $production->id] = (int) $establishmentId;
            $this->attachApplication((int) $production->app_id, (int) $establishmentId, $production->created_at, $production->updated_at);
        }

        foreach ($mapping as $legacyProductionId => $establishmentId) {
            foreach (array_keys($this->productionForeignKeys) as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'production_id')) {
                    continue;
                }
                DB::table($table)->where('production_id', $legacyProductionId)->update(['production_id' => $establishmentId]);
            }
        }

        $this->addForeignKeysToEstablishments();
        Schema::drop('productions');
        $this->createCompatibilityView();
    }

    public function down(): void
    {
        throw new RuntimeException('A consolidação de productions em establishments é uma migração estrutural irreversível. Restaure a partir de backup se necessário.');
    }

    private function extendEstablishments(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            if (! Schema::hasColumn('establishments', 'app_slug')) $table->string('app_slug', 64)->nullable()->after('app_id')->index();
            if (! Schema::hasColumn('establishments', 'legacy_production_id')) $table->unsignedBigInteger('legacy_production_id')->nullable()->after('source_establishment_id')->unique();
            if (! Schema::hasColumn('establishments', 'establishment_type')) $table->string('establishment_type')->nullable();
            if (! Schema::hasColumn('establishments', 'country')) $table->string('country')->nullable();
            if (! Schema::hasColumn('establishments', 'capacity')) $table->integer('capacity')->nullable();
            if (! Schema::hasColumn('establishments', 'start_date')) $table->date('start_date')->nullable();
            if (! Schema::hasColumn('establishments', 'end_date')) $table->date('end_date')->nullable();
            if (! Schema::hasColumn('establishments', 'contact_email')) $table->string('contact_email')->nullable();
            if (! Schema::hasColumn('establishments', 'contact_phone')) $table->string('contact_phone')->nullable();
            if (! Schema::hasColumn('establishments', 'ticket_price_min')) $table->decimal('ticket_price_min', 10, 2)->nullable();
            if (! Schema::hasColumn('establishments', 'ticket_price_max')) $table->decimal('ticket_price_max', 10, 2)->nullable();
            if (! Schema::hasColumn('establishments', 'total_tickets_sold')) $table->integer('total_tickets_sold')->nullable();
            if (! Schema::hasColumn('establishments', 'total_tickets_available')) $table->integer('total_tickets_available')->nullable();
            if (! Schema::hasColumn('establishments', 'other_information')) $table->text('other_information')->nullable();
            if (! Schema::hasColumn('establishments', 'images')) $table->longText('images')->nullable();
            if (! Schema::hasColumn('establishments', 'city_id')) $table->unsignedBigInteger('city_id')->nullable()->index();
            if (! Schema::hasColumn('establishments', 'address_number')) $table->string('address_number', 30)->nullable();
            if (! Schema::hasColumn('establishments', 'neighborhood')) $table->string('neighborhood', 160)->nullable();
            if (! Schema::hasColumn('establishments', 'address_complement')) $table->string('address_complement')->nullable();
            if (! Schema::hasColumn('establishments', 'address_reference')) $table->string('address_reference')->nullable();
            if (! Schema::hasColumn('establishments', 'formatted_address')) $table->string('formatted_address', 700)->nullable();
            if (! Schema::hasColumn('establishments', 'latitude')) $table->decimal('latitude', 10, 7)->nullable();
            if (! Schema::hasColumn('establishments', 'longitude')) $table->decimal('longitude', 10, 7)->nullable();
            if (! Schema::hasColumn('establishments', 'place_id')) $table->string('place_id')->nullable();
            if (! Schema::hasColumn('establishments', 'google_maps_url')) $table->string('google_maps_url', 2048)->nullable();
            if (! Schema::hasColumn('establishments', 'location_public')) $table->boolean('location_public')->default(false);
        });
    }

    private function resolveEstablishmentId(object $production): ?int
    {
        if (! empty($production->establishment_id)) {
            $exists = DB::table('establishments')->where('id', $production->establishment_id)->exists();
            if ($exists) return (int) $production->establishment_id;
        }

        $legacy = DB::table('establishments')->where('legacy_production_id', $production->id)->value('id');
        if ($legacy) return (int) $legacy;

        if (! empty($production->slug)) {
            $match = DB::table('establishments')
                ->where('app_id', $production->app_id)
                ->where('slug', $production->slug)
                ->where('category', 'production')
                ->value('id');
            if ($match) return (int) $match;
        }

        return null;
    }

    private function uniqueEstablishmentSlug(string $value, ?int $ignoreId, int $legacyProductionId): string
    {
        $base = Str::slug($value) ?: 'production-' . $legacyProductionId;
        $candidate = $base;
        $suffix = 2;

        while (DB::table('establishments')
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('slug', $candidate)
            ->exists()) {
            $candidate = $base . '-production-' . $legacyProductionId . ($suffix > 2 ? '-' . $suffix : '');
            $suffix++;
        }

        return $candidate;
    }

    private function attachApplication(int $applicationId, int $establishmentId, mixed $createdAt, mixed $updatedAt): void
    {
        if (! Schema::hasTable('application_establishment')) return;

        DB::table('application_establishment')->updateOrInsert(
            ['application_id' => $applicationId, 'establishment_id' => $establishmentId],
            [
                'is_primary' => true,
                'created_at' => $createdAt ?: now(),
                'updated_at' => $updatedAt ?: now(),
            ]
        );
    }

    private function dropForeignKeysToLegacyProductions(): void
    {
        foreach ($this->productionForeignKeys as $table => $constraint) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'production_id')) continue;

            if (DB::getDriverName() === 'sqlite') {
                // Laravel 12 rebuilds the SQLite table for dropForeign. Passing the
                // column is required because SQLite does not persist constraint names.
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropForeign(['production_id']);
                });
                continue;
            }

            DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $constraint));
        }
    }

    private function addForeignKeysToEstablishments(): void
    {
        foreach ($this->productionForeignKeys as $table => $constraint) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'production_id')) continue;

            Schema::table($table, function (Blueprint $blueprint) use ($table, $constraint) {
                $foreign = $blueprint->foreign('production_id', $constraint)->references('id')->on('establishments');
                if (in_array($table, $this->nullableProductionForeignKeys, true)) {
                    $foreign->nullOnDelete();
                } else {
                    $foreign->restrictOnDelete();
                }
            });
        }
    }

    private function createCompatibilityView(): void
    {
        DB::statement(<<<'SQL'
CREATE VIEW productions AS
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
