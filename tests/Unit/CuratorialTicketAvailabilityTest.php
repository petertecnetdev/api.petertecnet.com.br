<?php

namespace Tests\Unit;

use App\Models\Ticket;
use Tests\TestCase;

final class CuratorialTicketAvailabilityTest extends TestCase
{
    public function test_regular_ticket_with_inventory_is_available(): void
    {
        $ticket = new Ticket(['quantity' => 20, 'price' => 50]);

        $this->assertTrue($ticket->available);
        $this->assertTrue($ticket->toArray()['available']);
    }

    public function test_curatorial_ticket_stays_unavailable_in_public_serialization(): void
    {
        $ticket = new Ticket(['quantity' => 20, 'price' => 50]);
        $ticket->setAttribute('available', false);

        $this->assertFalse($ticket->available);
        $this->assertFalse($ticket->toArray()['available']);
        $this->assertSame('50.00', $ticket->toArray()['price']);
    }

    public function test_explicit_true_cannot_override_missing_inventory(): void
    {
        $ticket = new Ticket(['quantity' => 0, 'price' => 50]);
        $ticket->setAttribute('available', true);

        $this->assertFalse($ticket->available);
    }
}
