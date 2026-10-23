<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Ticket;
use App\Services\CuratorialTicketSynchronizer;
use PHPUnit\Framework\TestCase;

final class CuratorialTicketSynchronizerTest extends TestCase
{
    public function test_keeps_confirmed_free_and_paid_prices_without_duplicates(): void
    {
        self::assertSame([
            ['name' => 'Inteira', 'price' => 90.0],
            ['name' => 'Meia-entrada', 'price' => 0.0],
        ], CuratorialTicketSynchronizer::normalizeOptions([
            ['name' => 'Inteira', 'price' => 90],
            ['name' => 'Inteira ', 'price' => '90'],
            ['name' => 'Meia-entrada', 'price' => 0],
            ['name' => '', 'price' => 20],
            ['name' => 'Preço inválido', 'price' => -1],
            ['name' => 'Desconhecido', 'price' => null],
        ]));
    }

    public function test_unknown_price_is_nullable_and_not_misrepresented_as_free(): void
    {
        self::assertSame(
            [['name' => 'Ingressos — valor a confirmar', 'price' => null]],
            CuratorialTicketSynchronizer::normalizeOptions([])
        );
    }

    public function test_informational_tickets_never_become_available_even_with_quantity(): void
    {
        $ticket = new Ticket();
        $ticket->forceFill([
            'price' => null,
            'quantity' => 100,
            'extras' => [
                'curation_information_only' => true,
                'price_known' => false,
            ],
        ]);
        self::assertTrue($ticket->informational_only);
        self::assertFalse($ticket->price_known);
        self::assertSame(0, $ticket->remaining);
        self::assertFalse($ticket->available);
        self::assertNull($ticket->price);
    }

    public function test_non_informational_ticket_remains_normal(): void
    {
        $ticket = new Ticket();
        $ticket->forceFill(['quantity' => 2, 'price' => 0]);
        self::assertFalse($ticket->informational_only);
        self::assertTrue($ticket->price_known);
        self::assertSame(2, $ticket->remaining);
    }
}
