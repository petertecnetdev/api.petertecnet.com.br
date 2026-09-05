<?php

namespace App\Domain\Organizations\Support;

final class OrganizationTaxonomy
{
    public const TYPE_COMPANY = 'company';
    public const TYPE_VENUE = 'venue';
    public const TYPE_COLLECTIVE = 'collective';
    public const TYPE_INDIVIDUAL = 'individual';

    public const ROLE_PRODUCER = 'producer';
    public const ROLE_VENUE = 'venue';
    public const ROLE_ORGANIZER = 'organizer';
    public const ROLE_PROMOTER = 'promoter';

    public static function types(): array
    {
        return [
            self::TYPE_COMPANY => 'Produtora',
            self::TYPE_VENUE => 'Casa / espaço de eventos',
            self::TYPE_COLLECTIVE => 'Coletivo',
            self::TYPE_INDIVIDUAL => 'Produtor independente',
        ];
    }

    public static function roles(): array
    {
        return [
            self::ROLE_PRODUCER => 'Produz eventos',
            self::ROLE_VENUE => 'Sedia eventos',
            self::ROLE_ORGANIZER => 'Organiza eventos',
            self::ROLE_PROMOTER => 'Promove e divulga eventos',
        ];
    }

    public static function defaults(): array
    {
        return [
            self::TYPE_COMPANY => [self::ROLE_PRODUCER],
            self::TYPE_VENUE => [self::ROLE_VENUE],
            self::TYPE_COLLECTIVE => [self::ROLE_PRODUCER, self::ROLE_ORGANIZER],
            self::TYPE_INDIVIDUAL => [self::ROLE_PRODUCER, self::ROLE_ORGANIZER],
        ];
    }

    public static function legacyAliases(): array
    {
        return [
            'production' => self::TYPE_COMPANY,
            'producer' => self::TYPE_COMPANY,
            'produtora' => self::TYPE_COMPANY,
            'fixed' => self::TYPE_VENUE,
            'house' => self::TYPE_VENUE,
            'casa' => self::TYPE_VENUE,
            'independent' => self::TYPE_INDIVIDUAL,
            'independent_producer' => self::TYPE_INDIVIDUAL,
            'producer_independent' => self::TYPE_INDIVIDUAL,
            'coletivo' => self::TYPE_COLLECTIVE,
        ];
    }

    public static function normalizeType(?string $type): string
    {
        $value = strtolower(trim((string) $type));
        if ($value === '') {
            return self::TYPE_COMPANY;
        }

        if (array_key_exists($value, self::types())) {
            return $value;
        }

        return self::legacyAliases()[$value] ?? self::TYPE_COMPANY;
    }

    public static function normalizeRoles(mixed $roles, ?string $type = null): array
    {
        if (is_string($roles)) {
            $decoded = json_decode($roles, true);
            $roles = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $roles, -1, PREG_SPLIT_NO_EMPTY);
        }

        $allowed = array_keys(self::roles());
        $normalized = collect(is_array($roles) ? $roles : [])
            ->map(fn ($role) => strtolower(trim((string) $role)))
            ->filter(fn ($role) => in_array($role, $allowed, true))
            ->unique()
            ->values()
            ->all();

        if ($normalized !== []) {
            return $normalized;
        }

        $canonicalType = self::normalizeType($type);
        return self::defaults()[$canonicalType] ?? [self::ROLE_PRODUCER];
    }

    public static function payload(): array
    {
        return [
            'types' => collect(self::types())->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])->values()->all(),
            'roles' => collect(self::roles())->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])->values()->all(),
            'defaults' => self::defaults(),
            'legacy_aliases' => self::legacyAliases(),
        ];
    }
}
