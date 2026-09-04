<?php

namespace App\Domain\Finance\Services;

use InvalidArgumentException;

final class PixBrCodeService
{
    public function normalizeKey(string $type, string $key): string
    {
        $type = strtolower(trim($type));
        $key = trim($key);

        return match ($type) {
            'cpf' => $this->digitsKey($key, 11, 'CPF'),
            'cnpj' => $this->digitsKey($key, 14, 'CNPJ'),
            'email' => $this->emailKey($key),
            'phone' => $this->phoneKey($key),
            'random' => $this->randomKey($key),
            default => throw new InvalidArgumentException('Tipo de chave PIX inválido.'),
        };
    }

    public function maskKey(string $type, string $key): string
    {
        return match (strtolower($type)) {
            'email' => $this->maskEmail($key),
            'phone' => strlen($key) > 6 ? substr($key, 0, 3).'******'.substr($key, -4) : '***',
            'random' => strlen($key) > 12 ? substr($key, 0, 6).'…'.substr($key, -4) : '***',
            default => strlen($key) > 4 ? str_repeat('*', max(3, strlen($key) - 4)).substr($key, -4) : '***',
        };
    }

    public function generate(
        string $pixKey,
        string $holderName,
        string $merchantCity,
        float $amount,
        string $txid,
        ?string $description = null,
    ): string {
        if ($amount <= 0) {
            throw new InvalidArgumentException('O valor do PIX deve ser maior que zero.');
        }

        $holder = $this->ascii($holderName, 25);
        $city = $this->ascii($merchantCity, 15);
        $txid = preg_replace('/[^A-Z0-9]/', '', strtoupper($txid)) ?: '***';
        $txid = substr($txid, 0, 25);

        $merchantAccount = $this->field('00', 'BR.GOV.BCB.PIX')
            .$this->field('01', $pixKey);

        $description = trim((string) $description);
        if ($description !== '') {
            // O subcampo 26 inteiro precisa caber em dois dígitos de tamanho.
            $remaining = max(0, 99 - strlen($merchantAccount) - 4);
            if ($remaining > 0) {
                $merchantAccount .= $this->field('02', substr($this->ascii($description, $remaining), 0, $remaining));
            }
        }

        $additional = $this->field('05', $txid);
        $payload = $this->field('00', '01')
            .$this->field('26', $merchantAccount)
            .$this->field('52', '0000')
            .$this->field('53', '986')
            .$this->field('54', number_format($amount, 2, '.', ''))
            .$this->field('58', 'BR')
            .$this->field('59', $holder)
            .$this->field('60', $city)
            .$this->field('62', $additional)
            .'6304';

        return $payload.$this->crc16($payload);
    }

    private function field(string $id, string $value): string
    {
        $length = strlen($value);
        if ($length > 99) {
            throw new InvalidArgumentException("Campo PIX {$id} excede 99 bytes.");
        }

        return $id.str_pad((string) $length, 2, '0', STR_PAD_LEFT).$value;
    }

    private function digitsKey(string $key, int $length, string $label): string
    {
        $digits = preg_replace('/\D+/', '', $key) ?: '';
        if (strlen($digits) !== $length) {
            throw new InvalidArgumentException("Chave PIX {$label} inválida.");
        }

        return $digits;
    }

    private function emailKey(string $key): string
    {
        $email = strtolower($key);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Chave PIX de e-mail inválida.');
        }

        return $email;
    }

    private function phoneKey(string $key): string
    {
        $digits = preg_replace('/\D+/', '', $key) ?: '';
        if (in_array(strlen($digits), [10, 11], true)) {
            $digits = '55'.$digits;
        }
        if (strlen($digits) < 12 || strlen($digits) > 14) {
            throw new InvalidArgumentException('Chave PIX de telefone inválida. Informe DDI e número válidos.');
        }

        return '+'.$digits;
    }

    private function randomKey(string $key): string
    {
        $value = strtolower($key);
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            throw new InvalidArgumentException('Chave PIX aleatória inválida.');
        }

        return $value;
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $prefix = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $prefix.'***@'.$domain;
    }

    private function ascii(string $value, int $maxLength): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value));
        $value = strtoupper($converted !== false ? $converted : trim($value));
        $value = preg_replace('/[^A-Z0-9 .\-]/', '', $value) ?: '';
        $value = trim(preg_replace('/\s+/', ' ', $value) ?: '');

        if ($value === '') {
            throw new InvalidArgumentException('Nome/cidade do recebedor inválido para o PIX.');
        }

        return substr($value, 0, $maxLength);
    }

    private function crc16(string $payload): string
    {
        $crc = 0xFFFF;
        $length = strlen($payload);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($payload[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000)
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
