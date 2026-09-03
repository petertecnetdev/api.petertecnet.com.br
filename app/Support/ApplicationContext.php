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

    /**
     * Return the declared capabilities exposed to clients.
     *
     * An empty list can mean either an explicitly restricted application with
     * no capabilities or a legacy/unconfigured application. Use constrained()
     * when that distinction matters.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->declaredCapabilities() ?? [];
    }

    public function constrained(): bool
    {
        return $this->declaredCapabilities() !== null;
    }

    public function supports(string $capability): bool
    {
        $declared = $this->declaredCapabilities();

        // Applications created before capability declarations were introduced
        // remain compatible with the generic API. Once either the persisted
        // field or configuration explicitly declares a list, that list becomes
        // the allow-list. An explicitly persisted [] therefore means "none".
        if ($declared === null) {
            return true;
        }

        return in_array(trim($capability), $declared, true);
    }

    public function option(string $path, mixed $default = null): mixed
    {
        return config('platform.applications.'.$this->slug().'.'.$path, $default);
    }

    public function requireCapability(string $capability): void
    {
        abort_unless($this->supports($capability), 404, 'Esta capacidade não está habilitada para a aplicação atual.');
    }

    /** @return list<string>|null */
    private function declaredCapabilities(): ?array
    {
        $persisted = $this->application()->capabilities;
        if ($persisted !== null) {
            return $this->normalizeCapabilities((array) $persisted);
        }

        $configured = config('platform.applications.'.$this->slug().'.capabilities');
        if ($configured !== null) {
            return $this->normalizeCapabilities((array) $configured);
        }

        return null;
    }

    /** @return list<string> */
    private function normalizeCapabilities(array $capabilities): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($capability) => trim((string) $capability),
            $capabilities,
        ))));
    }
}
