<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstablishmentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'app_id' => $this->app_id,
            'name' => $this->name,
            'fantasy' => $this->fantasy,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'city' => $this->city,
            'uf' => $this->uf,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'published' => (bool) $this->is_published,
            'approved' => (bool) $this->is_approved,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
