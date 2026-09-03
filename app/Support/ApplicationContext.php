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
        $configured = $this->application()->capabilities;

        // Existing applications predate capability declarations. Null means
        // legacy-compatible during rollout; an explicit [] means no optional
        // capability is enabled. New applications are created with [].
        if ($configured === null && (bool) config('platform.legacy_unconfigured_capabilities', true)) {
            return ['*'];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($capability) => is_string($capability) ? trim($capability) : '',
            is_array($configured) ? $configured : []
        ))));
    }

    public function supports(string $capability): bool
    {
        $capabilities = $this->capabilities();

        return in_array('*', $capabilities, true)
            || in_array($capability, $capabilities, true);
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
