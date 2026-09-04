<?php

namespace App\Domain\Media\Services;

use App\Models\AmbientMedia;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AmbientMediaService
{
    private const SLOT = 'background_music';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AmbientMediaSubjectResolver $subjects,
    ) {}

    public function publicMedia(string $subjectType, int $subjectId): ?AmbientMedia
    {
        $this->subjects->resolvePublic($subjectType, $subjectId);

        return $this->query($subjectType, $subjectId)->where('enabled', true)->first();
    }

    public function managedMedia(string $subjectType, int $subjectId, User $user): ?AmbientMedia
    {
        $this->subjects->resolveOwned($subjectType, $subjectId, $user);

        return $this->query($subjectType, $subjectId)->first();
    }

    public function upsert(string $subjectType, int $subjectId, User $user, array $data): AmbientMedia
    {
        $this->subjects->resolveOwned($subjectType, $subjectId, $user);
        $data['source_url'] = trim((string) $data['source_url']);
        $this->validateProviderUrl($data['provider'], $data['source_url']);
        $metadata = $this->providerMetadata($data['provider'], $data['source_url']);

        return AmbientMedia::query()->updateOrCreate([
            'app_id' => $this->context->id(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'slot' => self::SLOT,
        ], [
            'provider' => $data['provider'],
            'source_url' => $data['source_url'],
            'external_id' => $this->externalId($data['provider'], $data['source_url']),
            'title' => $this->cleanNullable($data['title'] ?? null) ?: ($metadata['title'] ?? null),
            'artist' => $this->cleanNullable($data['artist'] ?? null) ?: ($metadata['artist'] ?? null),
            'thumbnail_url' => $metadata['thumbnail_url'] ?? null,
            'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : true,
            'autoplay' => array_key_exists('autoplay', $data) ? (bool) $data['autoplay'] : true,
            'loop' => array_key_exists('loop', $data) ? (bool) $data['loop'] : true,
            'volume' => (int) ($data['volume'] ?? 35),
            'start_seconds' => (int) ($data['start_seconds'] ?? 0),
            'metadata' => $metadata ?: null,
        ])->fresh();
    }

    public function remove(string $subjectType, int $subjectId, User $user): void
    {
        $this->subjects->resolveOwned($subjectType, $subjectId, $user);
        $this->query($subjectType, $subjectId)->delete();
    }

    public function recommendations(string $subjectType, int $subjectId, User $user): array
    {
        $this->subjects->resolveOwned($subjectType, $subjectId, $user);
        $seed = $this->query($subjectType, $subjectId)->where('enabled', true)->first();

        if (! $seed || ! trim((string) ($seed->title ?: $seed->artist))) {
            return ['seed' => $seed, 'recommendations' => []];
        }

        $term = trim(implode(' ', array_filter([$seed->artist, $seed->title])));
        $cacheKey = 'ambient-media:recommendations:' . sha1(Str::lower($term));
        $recommendations = Cache::remember($cacheKey, now()->addHours(6), function () use ($term, $seed) {
            try {
                $response = Http::acceptJson()->timeout(4)->retry(2, 150)->get('https://itunes.apple.com/search', [
                    'term' => $term,
                    'country' => 'BR',
                    'media' => 'music',
                    'entity' => 'song',
                    'limit' => 12,
                    'explicit' => 'Yes',
                ]);
                if (! $response->successful()) return [];

                return collect($response->json('results', []))
                    ->filter(fn ($row) => ! empty($row['trackName']) && ! empty($row['artistName']))
                    ->reject(fn ($row) => Str::lower(trim((string) $row['trackName'])) === Str::lower(trim((string) $seed->title)))
                    ->unique(fn ($row) => Str::lower(($row['artistName'] ?? '') . '|' . ($row['trackName'] ?? '')))
                    ->take(8)
                    ->map(function ($row) {
                        $query = trim(($row['artistName'] ?? '') . ' ' . ($row['trackName'] ?? ''));
                        return [
                            'title' => $row['trackName'] ?? null,
                            'artist' => $row['artistName'] ?? null,
                            'album' => $row['collectionName'] ?? null,
                            'genre' => $row['primaryGenreName'] ?? null,
                            'artwork_url' => isset($row['artworkUrl100']) ? str_replace('100x100bb', '300x300bb', $row['artworkUrl100']) : null,
                            'apple_url' => $row['trackViewUrl'] ?? null,
                            'youtube_search_url' => 'https://www.youtube.com/results?search_query=' . rawurlencode($query),
                            'spotify_search_url' => 'https://open.spotify.com/search/' . rawurlencode($query),
                        ];
                    })->values()->all();
            } catch (Throwable) {
                return [];
            }
        });

        return ['seed' => $seed, 'recommendations' => $recommendations];
    }

    private function query(string $subjectType, int $subjectId)
    {
        return AmbientMedia::query()->where([
            'app_id' => $this->context->id(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'slot' => self::SLOT,
        ]);
    }

    private function validateProviderUrl(string $provider, string $url): void
    {
        $parts = parse_url($url);
        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = Str::lower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https') {
            throw ValidationException::withMessages(['source_url' => ['Use uma URL HTTPS para evitar bloqueio de conteúdo no navegador.']]);
        }

        $valid = match ($provider) {
            'youtube' => in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtube-nocookie.com'], true),
            'spotify' => $host === 'open.spotify.com',
            'audio' => $host !== '',
            default => false,
        };

        if (! $valid) throw ValidationException::withMessages(['source_url' => ['A URL não corresponde ao provedor selecionado. Use o link completo do Spotify em open.spotify.com.']]);
        if ($provider !== 'audio' && ! $this->externalId($provider, $url)) {
            throw ValidationException::withMessages(['source_url' => ['Não foi possível identificar a música ou playlist nessa URL.']]);
        }
    }

    private function externalId(string $provider, string $url): ?string
    {
        $parts = parse_url($url);
        $host = Str::lower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($provider === 'youtube') {
            if ($host === 'youtu.be') return explode('/', $path)[0] ?: null;
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (! empty($query['v'])) return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $query['v']);
            if (preg_match('#^(?:embed|shorts)/([A-Za-z0-9_-]+)#', $path, $match)) return $match[1];
            if (preg_match('#^playlist$#', $path) && ! empty($query['list'])) return 'playlist:' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $query['list']);
        }

        if ($provider === 'spotify' && preg_match('#^(track|album|playlist)/([A-Za-z0-9]+)#', $path, $match)) {
            return $match[1] . ':' . $match[2];
        }

        return null;
    }

    private function providerMetadata(string $provider, string $url): array
    {
        if ($provider === 'audio') return [];
        try {
            $endpoint = $provider === 'youtube' ? 'https://www.youtube.com/oembed' : 'https://open.spotify.com/oembed';
            $params = $provider === 'youtube' ? ['url' => $url, 'format' => 'json'] : ['url' => $url];
            $response = Http::acceptJson()->timeout(4)->get($endpoint, $params);
            if (! $response->successful()) return [];

            return array_filter([
                'title' => $response->json('title'),
                'artist' => $provider === 'youtube' ? $response->json('author_name') : null,
                'thumbnail_url' => $response->json('thumbnail_url'),
                'provider_name' => $response->json('provider_name'),
            ], fn ($value) => $value !== null && $value !== '');
        } catch (Throwable) {
            return [];
        }
    }

    private function cleanNullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
