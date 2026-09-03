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

    public function supports(string $capability): bool
    {
        return in_array($capability, (array) config('platform.applications.' . $this->slug() . '.capabilities', []), true);
    }

    public function option(string $path, mixed $default = null): mixed
    {
        return config('platform.applications.' . $this->slug() . '.' . $path, $default);
    }

    public function requireCapability(string $capability): void
    {
        abort_unless($this->supports($capability), 404, 'Esta capacidade não está habilitada para a aplicação atual.');
    }
}
