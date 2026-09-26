<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\FlyerDescriptionContextService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlyerDescriptionContextServiceTest extends TestCase
{
    public function test_it_extracts_only_observed_flyer_facts_and_reports_conflicts(): void
    {
        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.vision_model' => 'vision-test',
        ]);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'facts' => [
                        'title' => 'Noite Editorial',
                        'dates' => ['10 de outubro'],
                        'times' => ['22h'],
                        'venue' => 'Casa Teste',
                        'artists' => ['DJ Exemplo'],
                        'ticket_options' => ['Primeiro lote — 20'],
                        'rules' => ['18+'],
                    ],
                    'conflicts' => ['A arte mostra 22h; o campo confirmado mostra 21h.'],
                    'warnings' => ['Moeda do preço não está legível.'],
                    'confidence' => 0.87,
                ], JSON_UNESCAPED_UNICODE),
            ], 200),
        ]);

        $service = app(FlyerDescriptionContextService::class);
        $result = $service->augment([
            'entity_type' => 'event',
            'use_attached_media' => true,
            'context' => ['start_date' => '2026-10-10 21:00:00'],
            'media' => [[
                'kind' => 'flyer',
                'data_url' => 'data:image/png;base64,' . base64_encode('tiny-image'),
            ]],
        ], new User(['email' => 'producer@example.test']));

        $this->assertSame('used', $result['meta']['media_status']);
        $this->assertSame(0.87, $result['meta']['media_confidence']);
        $this->assertStringContainsString('Noite Editorial', $result['data']['context']['flyer_observed_facts']);
        $this->assertStringContainsString('campo confirmado', $result['data']['context']['flyer_conflicts_review_required']);

        Http::assertSent(function (Request $request): bool {
            $content = (array) data_get($request->data(), 'input.0.content', []);
            return data_get($request->data(), 'model') === 'vision-test'
                && data_get($content, '1.type') === 'input_image'
                && str_starts_with((string) data_get($content, '1.image_url'), 'data:image/png;base64,');
        });
    }

    public function test_it_falls_back_to_confirmed_fields_when_media_is_unavailable(): void
    {
        config(['services.openai.api_key' => '']);

        $service = app(FlyerDescriptionContextService::class);
        $input = [
            'entity_type' => 'event',
            'use_attached_media' => true,
            'context' => ['title' => 'Evento confirmado'],
            'media' => [[
                'kind' => 'flyer',
                'data_url' => 'data:image/png;base64,' . base64_encode('tiny-image'),
            ]],
        ];

        $result = $service->augment($input, new User(['email' => 'producer@example.test']));

        $this->assertSame('unavailable', $result['meta']['media_status']);
        $this->assertSame($input['context'], $result['data']['context']);
        $this->assertNotEmpty($result['meta']['media_warnings']);
    }
}
