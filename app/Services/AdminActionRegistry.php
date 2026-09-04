<?php

namespace App\Services;

class AdminActionRegistry
{
    public function all(): array
    {
        return [
            'user.invite' => [
                'label' => 'Convidar usuário',
                'resource' => 'user',
                'operation' => 'create',
                'risk' => 'confirm',
                'executor' => 'onboarding',
                'required' => ['name', 'email', 'application'],
                'optional' => ['profile'],
                'supports_compound' => true,
            ],
            'establishment.create' => [
                'label' => 'Cadastrar estabelecimento',
                'resource' => 'establishment',
                'operation' => 'create',
                'risk' => 'confirm',
                'executor' => 'ecosystem',
                'required' => ['name', 'owner', 'application'],
                'optional' => ['fantasy', 'cnpj', 'email', 'phone', 'city', 'state', 'category', 'type', 'address', 'cep'],
                'supports_compound' => true,
            ],
            'item.create' => [
                'label' => 'Cadastrar item',
                'resource' => 'item',
                'operation' => 'create',
                'risk' => 'confirm',
                'executor' => 'ecosystem',
                'required' => ['name', 'establishment', 'application', 'price'],
                'optional' => ['type', 'description', 'category', 'subcategory', 'brand', 'duration', 'stock'],
                'supports_compound' => true,
            ],
            'ecosystem.search' => [
                'label' => 'Buscar no ecossistema',
                'resource' => 'ecosystem',
                'operation' => 'read',
                'risk' => 'automatic',
                'executor' => 'command-center',
                'required' => ['query'],
                'optional' => [],
                'supports_compound' => false,
            ],
            'admin.navigate' => [
                'label' => 'Navegar no Admin Center',
                'resource' => 'admin',
                'operation' => 'read',
                'risk' => 'automatic',
                'executor' => 'frontend',
                'required' => ['target'],
                'optional' => [],
                'supports_compound' => false,
            ],
        ];
    }

    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function publicCapabilities(): array
    {
        return collect($this->all())
            ->map(fn (array $definition, string $key) => ['key' => $key, ...$definition])
            ->values()
            ->all();
    }
}
