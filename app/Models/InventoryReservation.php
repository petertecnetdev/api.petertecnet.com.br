<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryReservation extends Model
{
    protected $fillable = [
        'application_id', 'tenant_type', 'tenant_id', 'resource_type', 'resource_id',
        'orderable_type', 'orderable_id', 'quantity', 'status', 'expires_at', 'released_at',
    ];

    protected $casts = ['expires_at' => 'datetime', 'released_at' => 'datetime'];

    public function tenant() { return $this->morphTo(); }
    public function resource() { return $this->morphTo(); }
    public function orderable() { return $this->morphTo(); }

    public function active(): bool
    {
        return $this->status === 'reserved' && ! $this->released_at && $this->expires_at?->isFuture();
    }
}
