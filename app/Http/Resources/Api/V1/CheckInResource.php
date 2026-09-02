<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckInResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'admission_credential_id' => $this->admission_credential_id,
            'actor_user_id' => $this->actor_user_id,
            'result' => $this->result,
            'device_id' => $this->device_id,
            'request_id' => $this->request_id,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
        ];
    }
}
