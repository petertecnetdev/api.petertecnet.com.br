<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdmissionTypeResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'event_id' => $this->event_id,
            'item_id' => $this->item_id,
            'name' => $this->name,
            'status' => $this->status,
            'price' => (float) $this->price,
            'capacity' => $this->capacity,
            'sales_start_at' => $this->sales_start_at?->toIso8601String(),
            'sales_end_at' => $this->sales_end_at?->toIso8601String(),
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
