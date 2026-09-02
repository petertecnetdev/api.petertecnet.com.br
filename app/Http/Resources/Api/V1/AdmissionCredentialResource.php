<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdmissionCredentialResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'application_id' => $this->application_id,
            'admission_type_id' => $this->admission_type_id,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_until' => $this->valid_until?->toIso8601String(),
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'credential_code' => $this->when($this->resource->getAttribute('credential_code'), $this->resource->getAttribute('credential_code')),
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
