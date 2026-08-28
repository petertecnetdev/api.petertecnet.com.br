<?php

namespace App\Support;

use App\Models\Application;
use RuntimeException;

class ApplicationContext
{
    private ?Application $application = null;

    public function set(Application $application): void
    {
        $this->application = $application;
    }

    public function clear(): void
    {
        $this->application = null;
    }

    public function has(): bool
    {
        return $this->application !== null;
    }

    public function application(): Application
    {
        if (! $this->application) {
            throw new RuntimeException('Application context has not been resolved for this request.');
        }

        return $this->application;
    }

    public function id(): int
    {
        return (int) $this->application()->getKey();
    }

    public function slug(): string
    {
        return (string) $this->application()->slug;
    }
}
