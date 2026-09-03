<?php

namespace App\Domain\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PublicFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->type,
            'mime_type' => $this->mime_type,
            'original_name' => $this->original_name,
            'public_url' => $this->public_url,
            'width' => $this->width,
            'height' => $this->height,
            'position' => $this->position,
            'sort_order' => $this->sort_order,
            'is_primary' => (bool) $this->is_primary,
            'variants' => $this->variants,
            'meta' => $this->meta,
        ];
    }
}
