<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'app_id' => $this->app_id,
            'establishment_id' => $this->entity_name === 'establishment' ? $this->entity_id : null,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price === null ? null : (float) $this->price,
            'status' => (bool) $this->status,
            'type' => $this->type ?? null,
            'category' => $this->category ?? null,
            'slug' => $this->slug ?? null,
            'image_url' => $this->image_url ?? null,
            'files' => $this->whenLoaded('files', fn () => $this->files->map(fn ($file) => [
                'id' => $file->id,
                'path' => $file->path ?? null,
                'url' => $file->url ?? null,
                'type' => $file->type ?? null,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
