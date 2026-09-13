<?php

namespace Tests\Unit;

use App\Mail\EventPassesMail;
use App\Models\Application;
use App\Models\CommerceOrder;
use App\Models\Event;
use Tests\TestCase;

class EventPassesMailTest extends TestCase
{
    public function test_event_pass_email_opens_configured_ticket_wallet(): void
    {
        $application = new Application([
            'name' => 'Eventos',
            'url' => 'https://eventos.example.com/',
            'runtime_settings' => [
                'routes' => [
                    'event_passes' => '/minha-carteira',
                ],
            ],
        ]);

        $event = new Event(['title' => 'Evento Teste']);
        $event->setRelation('application', $application);

        $order = new CommerceOrder();
        $order->setRelation('event', $event);

        $html = (new EventPassesMail($order, collect()))->render();

        $this->assertStringContainsString('href="https://eventos.example.com/minha-carteira"', $html);
        $this->assertStringContainsString('Ver meus ingressos', $html);
    }

    public function test_event_pass_email_rejects_external_wallet_route_and_uses_safe_fallback(): void
    {
        $application = new Application([
            'name' => 'Eventos',
            'url' => 'https://eventos.example.com',
            'runtime_settings' => [
                'routes' => [
                    'event_passes' => 'https://evil.example/pass',
                ],
            ],
        ]);

        $event = new Event(['title' => 'Evento Teste']);
        $event->setRelation('application', $application);

        $order = new CommerceOrder();
        $order->setRelation('event', $event);

        $html = (new EventPassesMail($order, collect()))->render();

        $this->assertStringContainsString('href="https://eventos.example.com/passes"', $html);
        $this->assertStringNotContainsString('evil.example', $html);
    }
}
