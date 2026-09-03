<?php

namespace App\Support;

final class TaxIdentifier
{
    public static function normalize(?string $value): ?string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '', strtoupper(trim((string) $value)));

        return $normalized !== '' ? $normalized : null;
    }

    public static function normalizeForCountry(?string $value, ?string $countryCode = 'BR'): ?string
    {
        $country = strtoupper(trim((string) ($countryCode ?: 'BR')));
        $normalized = self::normalize($value);

        if ($normalized === null) {
            return null;
        }

        return $country === 'BR'
            ? preg_replace('/\D+/', '', $normalized) ?: null
            : $normalized;
    }

    public static function type(?string $value, ?string $countryCode = 'BR'): ?string
    {
        $normalized = self::normalizeForCountry($value, $countryCode);
        if ($normalized === null) return null;

        if (strtoupper((string) $countryCode) === 'BR') {
            return match (strlen($normalized)) {
                11 => 'cpf',
                14 => 'cnpj',
                default => 'tax_id',
            };
        }

        return 'tax_id';
    }

    public static function isValid(?string $value, ?string $countryCode = 'BR'): bool
    {
        $normalized = self::normalizeForCountry($value, $countryCode);
        if ($normalized === null) return true;
        if (strtoupper((string) $countryCode) !== 'BR') return strlen($normalized) >= 3;

        return match (strlen($normalized)) {
            11 => self::validCpf($normalized),
            14 => self::validCnpj($normalized),
            default => false,
        };
    }

    private static function validCpf(string $value): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $value)) return false;

        for ($position = 9; $position <= 10; $position++) {
            $sum = 0;
            for ($index = 0; $index < $position; $index++) {
                $sum += ((int) $value[$index]) * (($position + 1) - $index);
            }
            $digit = ($sum * 10) % 11;
            if ($digit === 10) $digit = 0;
            if ($digit !== (int) $value[$position]) return false;
        }

        return true;
    }

    private static function validCnpj(string $value): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $value)) return false;

        $calculate = static function (string $base): int {
            $factor = strlen($base) - 7;
            $sum = 0;
            foreach (str_split($base) as $digit) {
                $sum += ((int) $digit) * $factor;
                $factor--;
                if ($factor < 2) $factor = 9;
            }
            $remainder = $sum % 11;
            return $remainder < 2 ? 0 : 11 - $remainder;
        };

        $first = $calculate(substr($value, 0, 12));
        $second = $calculate(substr($value, 0, 12) . $first);

        return substr($value, -2) === (string) $first . (string) $second;
    }
}
