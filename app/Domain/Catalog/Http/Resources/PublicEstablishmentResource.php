<?php

namespace App\Domain\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PublicEstablishmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $application = $this->resource->relationLoaded('app') ? $this->resource->app : null;
        $applications = $this->resource->relationLoaded('applications') ? $this->resource->applications : collect();
        $files = $this->resource->relationLoaded('files') ? $this->resource->files : collect();

        return [
            'id' => $this->id,
            'app_id' => $this->app_id,
            'name' => $this->name,
            'fantasy' => $this->fantasy,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'segments' => $this->segments,
            'city' => $this->city,
            'uf' => $this->uf,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->getAttribute('website') ?? $this->getAttribute('website_url') ?? $this->getAttribute('site'),
            'website_url' => $this->getAttribute('website_url') ?? $this->getAttribute('website') ?? $this->getAttribute('site'),
            'facebook_url' => $this->getAttribute('facebook_url') ?? $this->getAttribute('facebook'),
            'instagram_url' => $this->getAttribute('instagram_url') ?? $this->getAttribute('instagram'),
            'youtube_url' => $this->getAttribute('youtube_url') ?? $this->getAttribute('youtube'),
            'tiktok_url' => $this->getAttribute('tiktok_url') ?? $this->getAttribute('tiktok'),
            'address' => $this->address,
            'address_number' => $this->getAttribute('address_number') ?? $this->getAttribute('number'),
            'number' => $this->getAttribute('number') ?? $this->getAttribute('address_number'),
            'neighborhood' => $this->neighborhood,
            'postal_code' => $this->getAttribute('postal_code') ?? $this->getAttribute('cep'),
            'cep' => $this->getAttribute('cep') ?? $this->getAttribute('postal_code'),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'logo' => $this->logo,
            'cover' => $this->cover,
            'background' => $this->background,
            'business_profile' => $this->getAttribute('business_profile'),
            'catalog_active' => (bool) $this->getAttribute('catalog_active'),
            'catalog_shared' => (bool) $this->getAttribute('catalog_shared'),
            'is_featured' => (bool) $this->is_featured,
            'is_context_native' => (bool) $this->getAttribute('is_context_native'),
            'total_views' => (int) ($this->getAttribute('total_views') ?? 0),
            'application' => $application ? [
                'id' => $application->id,
                'name' => $application->name,
                'slug' => $application->slug,
                'logo' => $application->logo,
            ] : null,
            'app' => $application ? [
                'id' => $application->id,
                'name' => $application->name,
                'slug' => $application->slug,
                'logo' => $application->logo,
            ] : null,
            'applications' => $applications->map(fn ($app) => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'logo' => $app->logo,
            ])->values()->all(),
            'files' => PublicFileResource::collection($files)->resolve($request),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
