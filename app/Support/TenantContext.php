<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class TenantContext
{
    private ?Model $tenant = null;
    private ?string $type = null;

    public function set(Model $tenant, ?string $type = null): void
    {
        $this->tenant = $tenant;
        $this->type = $type ?: class_basename($tenant);
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->type = null;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): Model
    {
        if (! $this->tenant) {
            throw new RuntimeException('Tenant context has not been resolved for this request.');
        }

        return $this->tenant;
    }

    public function id(): int|string
    {
        return $this->tenant()->getKey();
    }

    public function type(): string
    {
        if (! $this->type) {
            throw new RuntimeException('Tenant context has not been resolved for this request.');
        }

        return $this->type;
    }
}
