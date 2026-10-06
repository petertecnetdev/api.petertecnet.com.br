<?php

namespace Tests\Unit;

use App\Services\AiDescriptionQualityService;
use PHPUnit\Framework\TestCase;

class AiDescriptionQualityServiceTest extends TestCase
{
    public function test_generic_boilerplate_is_rejected_as_low_value(): void
    {
        $service = new AiDescriptionQualityService();

        $this->assertTrue($service->isLowValue(
            'Confira as informações disponíveis, programe sua participação e acompanhe as atualizações do evento.'
        ));
    }

    public function test_original_richer_copy_scores_better_than_repeated_history(): void
    {
        $service = new AiDescriptionQualityService();

        $history = [
            'A quinta-feira chegou e agora é oficial: é hora de entrar no clima do fim de semana. Reúna os amigos e aproveite a noite na casa.',
        ];

        $repeated = $service->evaluate(
            'A quinta-feira chegou e agora é oficial: é hora de entrar no clima do fim de semana. Reúna os amigos e aproveite a noite na casa.',
            'quinta feira e dia bom pra sair da rotina antes da sexta',
            ['venue' => 'La Fyesta Pub', 'city' => 'Goiânia'],
            $history,
        );

        $original = $service->evaluate(
            "Quinta-feira ocupa aquele espaço em que a rotina começa a perder força e o fim de semana já aparece no horizonte. Na La Fyesta Pub, a ideia do produtor ganha uma versão mais clara: sair do automático antes mesmo da sexta-feira.\n\nO texto mantém essa proposta como centro da experiência, sem repetir a abertura usada nos eventos anteriores e sem adicionar atrações que não foram confirmadas.",
            'quinta feira e dia bom pra sair da rotina antes da sexta',
            ['venue' => 'La Fyesta Pub', 'city' => 'Goiânia'],
            $history,
        );

        $this->assertGreaterThan($repeated['score'], $original['score']);
        $this->assertGreaterThan($repeated['scores']['originality'], $original['scores']['originality']);
    }

    public function test_unknown_artist_claim_reduces_fidelity(): void
    {
        $service = new AiDescriptionQualityService();

        $quality = $service->evaluate(
            "A quinta-feira muda o ritmo da semana e cria um bom motivo para sair da rotina.\n\nO DJ convidado assume a pista durante a noite, criando o ponto central da programação.",
            'quinta feira e dia bom pra sair da rotina',
            ['venue' => 'La Fyesta Pub', 'artists' => ''],
            [],
        );

        $this->assertLessThan(100, $quality['scores']['fidelity']);
        $this->assertContains('menciona atração ou artista sem confirmação no evento atual', $quality['issues']);
    }

    public function test_unconfirmed_money_value_reduces_fidelity(): void
    {
        $service = new AiDescriptionQualityService();

        $quality = $service->evaluate(
            "Uma noite para sair da rotina com música e encontros.\n\nGaranta seu ingresso por R$ 49,90 e aproveite a programação.",
            'uma noite para sair da rotina',
            ['venue' => 'La Fyesta Pub', 'city' => 'Goiânia', 'ticket_options' => 'Pista — gratuito'],
            [],
        );

        $this->assertLessThan(100, $quality['scores']['fidelity']);
        $this->assertContains('contém valor monetário que não consta nos dados atuais', $quality['issues']);
        $this->assertFalse($quality['passes']);
    }
}
