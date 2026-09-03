<?php

namespace App\Domain\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PublicItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $filesLoaded = $this->resource->relationLoaded('files');
        $files = $filesLoaded ? $this->resource->files : collect();
        $establishment = $this->resource->relationLoaded('establishment') ? $this->resource->establishment : null;

        return [
            'id' => $this->id,
            'app_id' => $this->app_id,
            'entity_id' => $this->entity_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'type' => $this->type,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'brand' => $this->brand,
            'sku' => $this->sku,
            'price' => $this->price,
            'image' => $this->image,
            'image_url' => $filesLoaded ? $this->image_url : $this->image,
            'is_featured' => (bool) $this->is_featured,
            'tags' => $this->tags,
            'discount' => $this->discount,
            'availability_start' => $this->availability_start?->toISOString(),
            'availability_end' => $this->availability_end?->toISOString(),
            'total_views' => (int) ($this->getAttribute('total_views') ?? 0),
            'files' => PublicFileResource::collection($files)->resolve($request),
            'establishment' => $establishment ? (new PublicEstablishmentResource($establishment))->resolve($request) : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
