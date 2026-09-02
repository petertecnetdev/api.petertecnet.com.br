<?php

namespace App\Support;

use App\Models\User;
use RuntimeException;

final class ActorContext
{
    private ?User $user = null;
    private ?object $client = null;

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    public function setClient(?object $client): void
    {
        $this->client = $client;
    }

    public function clear(): void
    {
        $this->user = null;
        $this->client = null;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function client(): ?object
    {
        return $this->client;
    }

    public function authenticated(): bool
    {
        return $this->user !== null || $this->client !== null;
    }

    public function requireUser(): User
    {
        if (! $this->user) {
            throw new RuntimeException('Authenticated user actor is required for this operation.');
        }

        return $this->user;
    }
}
