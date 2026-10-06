<?php

namespace App\Services\WhatsApp;

use InvalidArgumentException;

class PhoneNumberNormalizer
{
    public function normalize(?string $phone): string
    {
        $value = trim((string) $phone);

        if ($value === '') {
            throw new InvalidArgumentException('WhatsApp destination is required.');
        }

        $value = preg_replace('/[^0-9+]/', '', $value) ?? '';

        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }

        if (! str_starts_with($value, '+')) {
            $callingCode = preg_replace('/\D+/', '', (string) config('services.whatsapp.default_calling_code')) ?? '';
            $digits = preg_replace('/\D+/', '', $value) ?? '';

            if ($callingCode === '') {
                throw new InvalidArgumentException('Phone number must be E.164 when no default calling code is configured.');
            }

            $value = '+'.$callingCode.ltrim($digits, '0');
        }

        if (! preg_match('/^\+[1-9]\d{7,14}$/', $value)) {
            throw new InvalidArgumentException('Invalid E.164 phone number.');
        }

        return $value;
    }

    public function metaRecipient(string $phone): string
    {
        return ltrim($this->normalize($phone), '+');
    }

    public function mask(string $phone): string
    {
        $normalized = $this->normalize($phone);
        $visible = min(4, max(2, strlen($normalized) - 4));

        return substr($normalized, 0, 3).str_repeat('*', max(4, strlen($normalized) - 3 - $visible)).substr($normalized, -$visible);
    }
}
