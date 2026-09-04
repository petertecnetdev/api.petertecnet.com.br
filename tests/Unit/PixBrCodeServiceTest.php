<?php

namespace Tests\Unit;

use App\Domain\Finance\Services\PixBrCodeService;
use PHPUnit\Framework\TestCase;

final class PixBrCodeServiceTest extends TestCase
{
    public function test_it_generates_a_valid_static_pix_payload_with_crc(): void
    {
        $service = new PixBrCodeService();

        $payload = $service->generate(
            '12345678909',
            'Joao da Silva',
            'Belo Horizonte',
            1250.50,
            'LOC123456789',
            'Aluguel setembro',
        );

        $this->assertStringStartsWith('000201', $payload);
        $this->assertStringContainsString('BR.GOV.BCB.PIX', $payload);
        $this->assertStringContainsString('54071250.50', $payload);
        $this->assertStringContainsString('5802BR', $payload);
        $this->assertMatchesRegularExpression('/6304[0-9A-F]{4}$/', $payload);
        $this->assertSame($this->crc16(substr($payload, 0, -4)), substr($payload, -4));
    }

    public function test_it_normalizes_and_masks_supported_keys(): void
    {
        $service = new PixBrCodeService();

        $this->assertSame('12345678909', $service->normalizeKey('cpf', '123.456.789-09'));
        $this->assertSame('+5531999999999', $service->normalizeKey('phone', '(31) 99999-9999'));
        $this->assertSame('pe***@example.com', $service->maskKey('email', 'peter@example.com'));
    }

    private function crc16(string $payload): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $length = strlen($payload); $i < $length; $i++) {
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
