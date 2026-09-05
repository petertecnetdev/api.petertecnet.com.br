<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Organizations\Support\OrganizationTaxonomy;
use App\Models\Application;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Interaction;
use App\Services\LocationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Event-organization projection over the generic establishments table.
 *
 * There is no organization-specific storage table. This model only provides
 * event-domain semantics and compatibility relationships while Establishment
 * remains the source of truth for every business entity in the ecosystem.
 */
class Organization extends Establishment
{
    protected $table = 'establishments';

    protected $fillable = [
        'app_id', 'app_slug', 'name', 'fantasy', 'cnpj', 'type', 'roles', 'slug',
        'establishment_type', 'phone', 'segments', 'description', 'location', 'cep',
        'address', 'city', 'uf', 'country', 'logo', 'background', 'capacity', 'user_id',
        'start_date', 'end_date', 'is_featured', 'contact_email', 'contact_phone',
        'is_published', 'is_approved', 'ticket_price_min', 'ticket_price_max',
        'total_tickets_sold', 'total_tickets_available', 'is_cancelled',
        'additional_info', 'facebook_url', 'website_url', 'twitter_url', 'instagram_url',
        'youtube_url', 'other_information', 'images', 'city_id', 'address_number',
        'neighborhood', 'address_complement', 'address_reference', 'formatted_address',
        'latitude', 'longitude', 'place_id', 'google_maps_url', 'location_public',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'roles' => 'array',
        'segments' => 'array',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'is_approved' => 'boolean',
        'is_cancelled' => 'boolean',
        'location_public' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('event_organization', fn (Builder $query) => $query->where('category', 'production'));

        static::creating(function (Organization $organization): void {
            $organization->category = 'production';
            $organization->establishment_type = $organization->establishment_type ?: 'production';
        });

        static::saving(function (Organization $organization): void {
            $organization->category = 'production';
            $organization->type = OrganizationTaxonomy::normalizeType($organization->type);
            $organization->roles = OrganizationTaxonomy::normalizeRoles($organization->roles, $organization->type);

            try {
                $data = app(LocationService::class)->canonicalize([
                    'city_id' => $organization->city_id,
                    'city' => $organization->city,
                    'uf' => $organization->uf,
                    'cep' => $organization->cep,
                    'address' => $organization->address,
                    'address_number' => $organization->address_number,
                    'neighborhood' => $organization->neighborhood,
                    'address_complement' => $organization->address_complement,
                    'address_reference' => $organization->address_reference,
                    'formatted_address' => $organization->formatted_address,
                    'latitude' => $organization->latitude,
                    'longitude' => $organization->longitude,
                    'place_id' => $organization->place_id,
                    'google_maps_url' => $organization->google_maps_url,
                ]);
                $organization->forceFill($data);
            } catch (Throwable $e) {
                report($e);
            }
        });

        static::saved(function (Organization $organization): void {
            if (! $organization->app_id || ! $organization->id || ! DB::getSchemaBuilder()->hasTable('application_establishment')) {
                return;
            }

            DB::table('application_establishment')->updateOrInsert(
                ['application_id' => $organization->app_id, 'establishment_id' => $organization->id],
                ['is_primary' => true, 'updated_at' => now(), 'created_at' => $organization->created_at ?: now()]
            );
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'production_id');
    }

    public function employers(): HasMany
    {
        return $this->hasMany(Employer::class, 'establishment_id');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class, 'entity_id')
            ->whereIn('entity_type', ['Production', 'Establishment', 'Organization']);
    }
}
