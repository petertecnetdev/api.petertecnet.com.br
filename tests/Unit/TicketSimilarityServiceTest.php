<?php

namespace Tests\Unit;

use App\Domain\Events\Services\TicketSimilarityService;
use App\Models\Ticket;
use Tests\TestCase;

class TicketSimilarityServiceTest extends TestCase
{
    public function test_it_marks_equivalent_lots_from_other_events_as_similar(): void
    {
        $source = new Ticket([
            'name' => '1º Lote',
            'type' => 'paid',
            'ticket_type' => 'standard',
            'price' => 25,
            'quantity' => 100,
            'sales_cutoff_mode' => 'after_start',
            'sales_cutoff_offset_minutes' => 120,
            'description' => 'Entrada individual',
        ]);
        $candidate = new Ticket([
            'name' => '1 Lote',
            'type' => 'paid',
            'ticket_type' => 'standard',
            'price' => 25,
            'quantity' => 100,
            'sales_cutoff_mode' => 'after_start',
            'sales_cutoff_offset_minutes' => 120,
            'description' => 'Entrada individual',
        ]);

        $match = app(TicketSimilarityService::class)->compare($source, $candidate);

        $this->assertTrue($match['similar']);
        $this->assertGreaterThanOrEqual(TicketSimilarityService::MINIMUM_SCORE, $match['score']);
        $this->assertContains('same_name', $match['reasons']);
    }

    public function test_it_rejects_unrelated_ticket_configurations(): void
    {
        $source = new Ticket([
            'name' => '1º Lote',
            'type' => 'paid',
            'ticket_type' => 'standard',
            'price' => 25,
            'quantity' => 100,
        ]);
        $candidate = new Ticket([
            'name' => 'Camarote VIP',
            'type' => 'paid',
            'ticket_type' => 'vip',
            'price' => 150,
            'quantity' => 20,
        ]);

        $match = app(TicketSimilarityService::class)->compare($source, $candidate);

        $this->assertFalse($match['similar']);
        $this->assertLessThan(TicketSimilarityService::MINIMUM_SCORE, $match['score']);
    }
}
