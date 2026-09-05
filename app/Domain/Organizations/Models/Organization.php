<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Organizations\Support\OrganizationTaxonomy;
use App\Models\Application;
use App\Models\BrazilianMunicipality;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Interaction;
use App\Services\LocationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event-organization projection over the generic establishments table.
 *
 * There is no organization-specific storage table. This model only provides
 * event-domain semantics while Establishment remains the source of truth for
 * every business entity in the ecosystem.
 */
class Organization extends Establishment
{
    protected $table = 'establishments';

    protected $fillable = [
        'app_id', 'app_slug', 'legacy_production_id', 'name', 'fantasy', 'cnpj', 'type', 'roles', 'slug',
        'establishment_type', 'phone', 'email', 'segments', 'description', 'location', 'cep',
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
        'ticket_price_min' => 'decimal:2',
        'ticket_price_max' => 'decimal:2',
        'total_tickets_sold' => 'integer',
        'total_tickets_available' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'city_id' => 'integer',
        'legacy_production_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('event_organization', function (Builder $query): void {
            $query->where($query->qualifyColumn('category'), 'production');
        });

        static::creating(function (Organization $organization): void {
            $organization->category = 'production';
            $organization->establishment_type = $organization->establishment_type ?: 'production';
            $organization->fantasy = $organization->fantasy ?: $organization->name;
            $organization->created_by = $organization->created_by ?: $organization->user_id;
            $organization->updated_by = $organization->updated_by ?: $organization->user_id;

            if (! $organization->app_slug && $organization->app_id) {
                $organization->app_slug = Application::query()->whereKey($organization->app_id)->value('slug');
            }
        });

        static::saving(function (Organization $organization): void {
            $organization->category = 'production';
            $organization->type = OrganizationTaxonomy::normalizeType($organization->type);
            $organization->roles = OrganizationTaxonomy::normalizeRoles($organization->roles, $organization->type);
            $organization->establishment_type = $organization->establishment_type ?: 'production';
            $organization->updated_by = $organization->updated_by ?: $organization->user_id;
            if (! $organization->phone && $organization->contact_phone) $organization->phone = $organization->contact_phone;
            if (! $organization->email && $organization->contact_email) $organization->email = $organization->contact_email;

            $locationDirty = ! $organization->exists || $organization->isDirty(['city_id', 'city', 'uf', 'cep']);
            if ($locationDirty) {
                $service = app(LocationService::class);
                if ($organization->cep) $organization->cep = $service->normalizeCep($organization->cep);
                if ($organization->city_id || $organization->city || $organization->uf) {
                    $data = ['city_id' => $organization->city_id, 'city' => $organization->city, 'uf' => $organization->uf];
                    $service->applyCanonicalCity($data, true);
                    $organization->city_id = $data['city_id'];
                    $organization->city = $data['city'];
                    $organization->uf = $data['uf'];
                }
            }
        });

        static::saved(function (Organization $organization): void {
            if (! $organization->app_id || ! $organization->id || ! Schema::hasTable('application_establishment')) return;

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

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(BrazilianMunicipality::class, 'city_id', 'ibge_code');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'production_id')->orderBy('start_date', 'desc');
    }

    public function employers(): HasMany
    {
        return $this->hasMany(Employer::class, 'establishment_id');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class, 'entity_id')
            ->whereIn('entity_type', ['production', 'Production', 'Establishment', 'Organization']);
    }
}
