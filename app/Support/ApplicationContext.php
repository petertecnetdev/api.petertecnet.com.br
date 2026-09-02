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

    public function capabilities(): array
    {
        $capabilities = config('platform.capabilities.' . $this->slug(), []);

        return array_values(array_unique(array_filter(array_map(
            static fn ($capability) => trim((string) $capability),
            is_array($capabilities) ? $capabilities : [],
        ))));
    }

    public function supports(string $capability): bool
    {
        return in_array(trim($capability), $this->capabilities(), true);
    }

    public function requireCapability(string $capability): void
    {
        abort_unless(
            $this->supports($capability),
            404,
            'Esta capacidade não está disponível para este aplicativo.',
        );
    }
}
