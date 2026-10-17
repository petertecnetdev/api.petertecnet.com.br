<?php

namespace App\Domain\EventDiscovery\Services;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class DiscoveryFeedService
{
    public function __construct(private readonly DiscoverySourcePolicy $policy) {}

    /**
     * Processa apenas JSON estruturado de fontes com autorização. NUNCA
     * publica ou altera um Event durante a coleta.
     *
     * @return array{received:int,accepted:int,skipped:int}
     */
    public function scan(object $source): array
    {
        $this->policy->assertAllowed($source->url);

        $response = Http::timeout(15)
            ->withOptions([
                'allow_redirects' => false,
                'verify' => true,
                'stream' => true,
                'force_ip_resolve' => 'v4',
            ])
            ->acceptJson()
            ->get($source->url);

        if (! $response->successful()) {
            throw new RuntimeException('Fonte retornou HTTP '.$response->status());
        }

        $maxBytes = (int) config('event_discovery.max_feed_bytes', 2097152);
        if ((int) $response->header('Content-Length', 0) > $maxBytes) {
            throw new RuntimeException('Feed excedeu o limite permitido.');
        }

        // Streaming impede carregar uma resposta arbitrariamente grande na memória.
        $body = '';
        $stream = $response->toPsrResponse()->getBody();
        while (! $stream->eof() && strlen($body) <= $maxBytes) {
            $chunk = $stream->read(min(65536, $maxBytes + 1 - strlen($body)));
            if ($chunk === '') break;
            $body .= $chunk;
        }
        if (strlen($body) > $maxBytes) {
            throw new RuntimeException('Feed excedeu o limite permitido.');
        }

        $decoded = json_decode($body, true, 64);
        if (! is_array($decoded)) {
            throw new RuntimeException('JSON inválido ou incompatível.');
        }

        $items = array_is_list($decoded) ? $decoded : ($decoded['events'] ?? null);
        if (! is_array($items) || ! array_is_list($items)) {
            throw new RuntimeException('A fonte precisa fornecer uma lista events[].');
        }

        $received = count($items);
        $max = (int) config('event_discovery.max_events_per_feed', 300);
        if ($received > $max) {
            throw new RuntimeException('Feed excedeu o número máximo de eventos.');
        }

        $accepted = 0;
        foreach ($items as $item) {
            if (! is_array($item)) continue;
            $event = $this->normalize($item, $source);
            if ($event === null) continue;

            $existing = DB::table('event_discovery_candidates')
                ->where('source_id', $source->id)
                ->where('source_event_key', $event['source_event_key'])
                ->first(['id', 'status']);

            if ($existing) {
                // A fonte pode atualizar informações, mas nunca reabrir uma
                // rejeição ou transformar automaticamente um rascunho.
                DB::table('event_discovery_candidates')->where('id', $existing->id)->update(
                    array_merge($event, ['last_seen_at' => now(), 'updated_at' => now()])
                );
                $accepted++;
                continue;
            }

            $matchingCandidate = DB::table('event_discovery_candidates')
                ->where('app_id', $source->app_id)
                ->where('content_key', $event['content_key'])
                ->exists();

            $nearby = Event::query()
                ->where('app_id', $source->app_id)
                ->where('uf', $event['uf'])
                ->where('start_date', '>=', CarbonImmutable::parse($event['start_at'])->subHours(3))
                ->where('start_date', '<=', CarbonImmutable::parse($event['start_at'])->addHours(3))
                ->limit(80)
                ->get(['title', 'city']);

            $matchingEvent = $nearby->contains(fn (Event $candidate) =>
                Str::slug((string) $candidate->title) === Str::slug($event['title'])
                && Str::slug((string) $candidate->city) === Str::slug($event['city'])
            );

            DB::table('event_discovery_candidates')->insert(array_merge($event, [
                'app_id' => $source->app_id,
                'source_id' => $source->id,
                'status' => ($matchingCandidate || $matchingEvent) ? 'possible_duplicate' : 'pending',
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $accepted++;
        }

        DB::table('event_discovery_sources')->where('id', $source->id)->update([
            'last_scanned_at' => now(),
            'last_error' => null,
            'updated_at' => now(),
        ]);

        return ['received' => $received, 'accepted' => $accepted, 'skipped' => $received - $accepted];
    }

    /** @return array<string,mixed>|null */
    public function normalize(array $item, object $source): ?array
    {
        $title = trim((string) ($item['title'] ?? ''));
        $external = trim((string) ($item['id'] ?? $item['url'] ?? ''));
        $url = trim((string) ($item['url'] ?? ''));
        $city = trim((string) ($item['city'] ?? $source->default_city ?? ''));
        $uf = strtoupper(trim((string) ($item['uf'] ?? $source->default_uf ?? '')));
        $timezone = (string) ($source->timezone ?: 'America/Sao_Paulo');

        if ($title === '' || mb_strlen($title) > 240 || $external === ''
            || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! str_starts_with(strtolower($url), 'https://')
            || $city === '' || mb_strlen($city) > 160
            || ! preg_match('/^[A-Z]{2}$/', $uf)
            || empty($item['start_at'])
        ) return null;

        try {
            $start = CarbonImmutable::parse((string) $item['start_at'], $timezone)->utc();
            $end = ! empty($item['end_at'])
                ? CarbonImmutable::parse((string) $item['end_at'], $timezone)->utc() : null;
        } catch (\Throwable) {
            return null;
        }

        if ($start->lessThan(now()->subHours(6))
            || $start->greaterThan(now()->addDays(400))
            || ($end && (! $end->greaterThan($start) || $end->greaterThan($start->addDays(14))))
        ) return null;

        $sourceKey = hash('sha256', $external);
        $contentKey = hash('sha256', implode('|', [
            Str::slug($title),
            $start->format('Y-m-d H:i'),
            Str::slug($city),
            $uf,
        ]));

        return [
            'source_event_key' => $sourceKey,
            'content_key' => $contentKey,
            'title' => $title,
            'description' => Str::limit(strip_tags((string) ($item['description'] ?? '')), 5000, ''),
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end?->format('Y-m-d H:i:s'),
            'city' => $city,
            'uf' => $uf,
            'venue' => Str::limit((string) ($item['venue'] ?? ''), 255, ''),
            'address' => Str::limit((string) ($item['address'] ?? ''), 500, ''),
            'organizer' => Str::limit((string) ($item['organizer'] ?? ''), 200, ''),
            'source_url' => $url,
            'evidence' => json_encode([
                'source_name' => $source->name,
                'source_event_id' => $external,
                'timezone' => $timezone,
                'retrieved_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }
}
