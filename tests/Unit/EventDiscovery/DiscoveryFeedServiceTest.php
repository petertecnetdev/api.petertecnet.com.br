<?php

namespace Tests\Unit\EventDiscovery;

use App\Domain\EventDiscovery\Services\DiscoveryFeedService;
use App\Domain\EventDiscovery\Services\DiscoverySourcePolicy;
use Tests\TestCase;

final class DiscoveryFeedServiceTest extends TestCase
{
    private function source(): object
    {
        return (object) [
            'id' => 12,
            'app_id' => 4,
            'name' => 'Agenda autorizada',
            'url' => 'https://agenda.example.org/feed.json',
            'default_city' => 'Brasília',
            'default_uf' => 'DF',
            'timezone' => 'America/Sao_Paulo',
        ];
    }

    public function test_normaliza_evento_futuro_com_chave_deterministica(): void
    {
        $service = new DiscoveryFeedService(new DiscoverySourcePolicy());
        $start = now()->addDays(5)->setTime(19, 30)->toIso8601String();
        $event = [
            'id' => 'agenda-123',
            'title' => 'Encontro de música',
            'url' => 'https://agenda.example.org/eventos/123',
            'start_at' => $start,
            'venue' => 'Arena Central',
        ];

        $first = $service->normalize($event, $this->source());
        $second = $service->normalize($event, $this->source());

        $this->assertNotNull($first);
        $this->assertSame($first['source_event_key'], $second['source_event_key']);
        $this->assertSame($first['content_key'], $second['content_key']);
        $this->assertSame('Brasília', $first['city']);
        $this->assertSame('DF', $first['uf']);
        $this->assertSame('Arena Central', $first['venue']);
    }

    public function test_descarta_evento_passado_sem_data_ou_link_seguro(): void
    {
        $service = new DiscoveryFeedService(new DiscoverySourcePolicy());
        $valid = [
            'id' => 'abc',
            'title' => 'Evento teste',
            'url' => 'https://agenda.example.org/eventos/abc',
            'start_at' => now()->addDays(4)->toIso8601String(),
        ];

        $this->assertNull($service->normalize(array_merge($valid, [
            'start_at' => now()->subDays(20)->toIso8601String(),
        ]), $this->source()));
        $this->assertNull($service->normalize(array_merge($valid, [
            'url' => 'http://agenda.example.org/eventos/abc',
        ]), $this->source()));
        $this->assertNull($service->normalize(array_merge($valid, [
            'start_at' => null,
        ]), $this->source()));
    }

    public function test_fonte_nao_autorizada_e_rede_local_sao_recusadas(): void
    {
        $policy = new DiscoverySourcePolicy();
        config()->set('event_discovery.allowed_hosts', ['agenda.example.org']);

        foreach ([
            'http://agenda.example.org/feed.json',
            'https://127.0.0.1/feed.json',
            'https://localhost/feed.json',
            'https://outra.example.org/feed.json',
            'https://usuario:senha@agenda.example.org/feed.json',
            'https://agenda.example.org:8443/feed.json',
        ] as $url) {
            try {
                $policy->assertAllowed($url);
                $this->fail('Fonte insegura aceita: '.$url);
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }
}
