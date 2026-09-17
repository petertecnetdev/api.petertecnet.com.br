<?php

namespace Tests\Unit;

use App\Services\AiDescriptionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiDescriptionServiceTest extends TestCase
{
    public function test_cloudflare_enriches_editorial_copy_and_keeps_operational_facts_deterministic(): void
    {
        config([
            'services.openai.api_key' => '',
            'creative.cloudflare.account_id' => 'test-account',
            'creative.cloudflare.api_token' => 'test-token',
            'creative.cloudflare.text_model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        ]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [
                    'response' => '**A quinta-feira muda o ritmo da semana.** Na La Fyesta Pub, o clima do fim de semana começa a aparecer antes da sexta.',
                    'usage' => [
                        'prompt_tokens' => 120,
                        'completion_tokens' => 42,
                        'total_tokens' => 162,
                    ],
                ],
            ], 200),
        ]);

        $service = app(AiDescriptionService::class);
        $result = $service->generateDescription([
            'entity_type' => 'event',
            'title' => 'Quinta-feira é dia de entrar no clima do fim de semana.',
            'current_description' => 'quinta feira e dia bom pra sair da rotina antes da sexta',
            'context' => [
                'entityId' => '229',
                'venue' => 'La Fyesta Pub',
                'city' => 'Goiânia',
                'uf' => 'GO',
                'start_date' => '2026-09-17T22:00',
                'end_date' => '2026-09-18T05:00',
                'ticket_options' => 'Lista VIP Mulher — gratuito; 1º Lote — R$ 10,00',
                'historical_style_1' => 'A sexta-feira chegou e agora é oficial: é hora de comemorar o fim da semana!',
            ],
        ], 10);

        $this->assertSame('@cf/meta/llama-3.3-70b-instruct-fp8-fast', $result['model']);
        $this->assertStringContainsString('A quinta-feira muda o ritmo da semana.', $result['description']);
        $this->assertStringContainsString('O evento começa às 22:00 e segue até 05:00 do dia seguinte.', $result['description']);
        $this->assertStringContainsString('Lista VIP Mulher — gratuito; 1º Lote — R$ 10,00', $result['description']);
        $this->assertStringNotContainsString('**', $result['description']);

        Http::assertSent(function (Request $request): bool {
            $messages = (array) $request->data()['messages'];
            $userPrompt = (string) data_get($messages, '1.content', '');

            return str_contains($userPrompt, 'REFERÊNCIAS HISTÓRICAS DA MESMA PRODUÇÃO')
                && str_contains($userPrompt, 'A sexta-feira chegou e agora é oficial')
                && ! str_contains($userPrompt, 'ticket_options');
        });
    }

    public function test_local_fallback_replaces_old_generic_boilerplate(): void
    {
        config([
            'services.openai.api_key' => '',
            'creative.cloudflare.account_id' => '',
            'creative.cloudflare.api_token' => '',
        ]);

        $service = app(AiDescriptionService::class);
        $result = $service->generateDescription([
            'entity_type' => 'event',
            'title' => 'Quinta-feira é dia de entrar no clima do fim de semana.',
            'current_description' => 'Confira as informações disponíveis, programe sua participação e acompanhe as atualizações do evento.',
            'context' => [
                'entityId' => '229',
                'venue' => 'La Fyesta Pub',
                'city' => 'Goiânia',
                'uf' => 'GO',
                'start_date' => '2026-09-17T22:00',
                'end_date' => '2026-09-18T05:00',
            ],
        ]);

        $this->assertSame('petertecnet-local-composer-v2', $result['model']);
        $this->assertStringNotContainsString('Confira as informações disponíveis', $result['description']);
        $this->assertStringContainsString('quinta-feira', mb_strtolower($result['description']));
        $this->assertStringContainsString('22:00', $result['description']);
    }
}
