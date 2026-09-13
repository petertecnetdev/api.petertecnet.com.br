<?php

namespace Tests\Feature;

use App\Mail\EventPassesMail;
use App\Models\CommerceOrder;
use App\Models\Event;
use App\Models\EventPass;
use Illuminate\Support\Collection;
use Tests\TestCase;

class EventPassesMailTest extends TestCase
{
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
