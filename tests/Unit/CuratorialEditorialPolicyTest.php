<?php

namespace Tests\Unit;

use App\Services\CuratorialEditorialPolicy;
use PHPUnit\Framework\TestCase;

final class CuratorialEditorialPolicyTest extends TestCase
{
    public function test_removes_external_ticketing_promotion_without_losing_event_information(): void
    {
        $raw = "Show com banda autoral e DJs convidados.\n\nEvento publicado pela curadoria da Cutinapp com base em informações públicas. Confirme horários, preços e disponibilidade na página oficial: https://www.sympla.com.br/evento/x/123";
        $result = CuratorialEditorialPolicy::removePromotionalReferences($raw);
        $this->assertSame('Show com banda autoral e DJs convidados.', $result);
        $this->assertStringNotContainsString('sympla', strtolower($result));
    }

    public function test_removes_last_batch_cta_and_preserves_description(): void
    {
        $raw = "Noite de rock independente.\n\nCadastro informativo feito pela curadoria da Cutinapp. O organizador ainda não reivindicou a página nesta plataforma. A Cutinapp não vende, emite ou reserva ingressos para este evento. Consulte horários, disponibilidade, valores e condições diretamente na fonte oficial: https://shotgun.live/pt-br/events/teste";
        $this->assertSame('Noite de rock independente.', CuratorialEditorialPolicy::removePromotionalReferences($raw));
    }

    public function test_keeps_organizers_official_websites_out_of_ticketing_blocklist(): void
    {
        $this->assertTrue(CuratorialEditorialPolicy::isTicketingSource('https://r.shotgun.live/pt-br/events/teste'));
        $this->assertTrue(CuratorialEditorialPolicy::isTicketingSource('https://www.sympla.com.br/evento/teste'));
        $this->assertFalse(CuratorialEditorialPolicy::isTicketingSource('https://infinu.com.br'));
        $this->assertFalse(CuratorialEditorialPolicy::isTicketingSource('https://naoshotgun.live.example.org/test'));
    }

    public function test_presents_date_venue_and_price_without_sending_users_elsewhere(): void
    {
        $event=(object)[
            'start_date'=>'2026-11-07 18:00:00','end_date'=>'2026-11-07 20:30:00',
            'venue'=>'Infinu','address'=>'CRS 506','city'=>'Brasília','uf'=>'DF','organizer_name'=>'Infinu',
        ];
        $result=CuratorialEditorialPolicy::buildEventDescription($event, 'Show de rock.', 'Entrada a partir de R$ 50.');
        $this->assertStringContainsString('07/11/2026', $result);
        $this->assertStringContainsString('CRS 506', $result);
        $this->assertStringContainsString('R$ 50', $result);
        $this->assertStringNotContainsString('http', $result);
    }
}
