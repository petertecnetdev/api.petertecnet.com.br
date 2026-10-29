<?php

namespace Tests\Feature;

use App\Mail\EventPassesMail;
use App\Models\CommerceOrder;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\Application;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Tests\TestCase;

class EventPassesMailTest extends TestCase
{
    public function test_ticket_email_contains_event_and_individual_wallet_links_without_exposing_qr(): void
    {
        $application = new Application([
            'name' => 'Cutinapp',
            'url' => 'https://cutinapp.example/',
        ]);
        $event = new Event([
            'title' => 'Festival do Centro',
            'slug' => 'festival-do-centro',
            'venue' => 'Praça Central',
            'city' => 'Goiânia',
            'uf' => 'GO',
            'start_date' => '2026-12-12 19:00:00',
        ]);
        $event->setRelation('application', $application);
        $order = new CommerceOrder(['public_id' => 'pedido-123']);
        $order->setRelation('event', $event);
        $pass = new EventPass([
            'holder_name' => 'Comprador Teste',
            'token' => 'SEGREDO-DO-QR',
            'status' => 'issued',
        ]);
        $pass->id = 49;
        $pass->setRelation('ticket', new Ticket(['name' => 'Meia-entrada']));

        $html = (new EventPassesMail($order, collect([$pass])))->render();

        $this->assertStringContainsString('https://cutinapp.example/event/festival-do-centro', $html);
        $this->assertStringContainsString('https://cutinapp.example/passes/49', $html);
        $this->assertStringContainsString('Praça Central', $html);
        $this->assertStringContainsString('Goiânia', $html);
        $this->assertStringContainsString('Meia-entrada', $html);
        $this->assertStringContainsString('pedido-123', $html);
        $this->assertStringContainsString('12/12/2026', $html);
        $this->assertStringContainsString('QR Code', $html);
        $this->assertStringNotContainsString('SEGREDO-DO-QR', $html);
    }

    public function test_event_passes_mail_renders_without_missing_view(): void
    {
        $event = new Event(['title' => 'Noite de Teste']);
        $order = new CommerceOrder();
        $order->setRelation('event', $event);

        $passes = new Collection([
            new EventPass([
                'holder_name' => 'Participante Teste',
                'holder_email' => 'participante@example.com',
            ]),
        ]);

        $html = (new EventPassesMail($order, $passes))->render();

        $this->assertStringContainsString('Seus ingressos estão prontos', $html);
        $this->assertStringContainsString('Noite de Teste', $html);
        $this->assertStringContainsString('Participante Teste', $html);
        $this->assertStringNotContainsString('token', strtolower($html));
    }
}
