<?php

namespace App\Domain\Identity\Services;

use Illuminate\Support\Str;

class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes(max($bytes, 16)));
    }

    public function uri(string $secret, string $account): string
    {
        $issuer = (string) config('identity.two_factor.issuer', 'Peter Tecnet');
        $label = rawurlencode($issuer . ':' . $account);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'period' => (int) config('identity.two_factor.period', 30),
            'digits' => (int) config('identity.two_factor.digits', 6),
            'algorithm' => 'SHA1',
        ]);
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $digits = (int) config('identity.two_factor.digits', 6);
        if (! preg_match('/^\d{' . $digits . '}$/', $code)) {
            return false;
        }

        $period = max((int) config('identity.two_factor.period', 30), 1);
        $window = max((int) config('identity.two_factor.window', 1), 0);
        $counter = intdiv($timestamp ?? time(), $period);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->code($secret, $counter + $offset, $digits), $code)) {
                return true;
            }
        }

        return false;
    }

    public function recoveryCodes(): array
    {
        $count = max((int) config('identity.two_factor.recovery_codes', 8), 4);

        return collect(range(1, $count))
            ->map(fn () => strtoupper(Str::random(5) . '-' . Str::random(5)))
            ->all();
    }

    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn ($code) => hash('sha256', strtoupper(trim((string) $code))), $codes);
    }

    private function code(string $secret, int $counter, int $digits): string
    {
        $key = $this->base32Decode($secret);
        if ($key === '') {
            return str_repeat('0', $digits);
        }

        $binaryCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        $modulo = 10 ** $digits;
        return str_pad((string) ($value % $modulo), $digits, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[^A-Z2-7]/', '', $encoded) ?? '');
        $bits = '';

        foreach (str_split($encoded) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position === false) {
                return '';
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }
}
