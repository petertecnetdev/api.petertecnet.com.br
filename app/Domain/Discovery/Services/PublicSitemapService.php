<?php

namespace App\Domain\Discovery\Services;

use App\Models\Application;
use App\Models\ContentEntry;
use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class PublicSitemapService
{
    private const MAX_CONTENT_URLS = 5000;
    private const MAX_EVENT_URLS = 15000;

    public function build(string $origin, ?string $applicationSlug = null): Collection
    {
        $origin = $this->normalizeOrigin($origin);
        $applicationSlug = trim((string) $applicationSlug);

        $entries = ContentEntry::query()
            ->published()
            ->forApplication($applicationSlug !== '' ? $applicationSlug : null)
            ->orderByDesc('updated_at')
            ->limit(self::MAX_CONTENT_URLS)
            ->get(['id', 'type', 'title', 'slug', 'category', 'metadata', 'published_at', 'updated_at']);

        $urls = collect();
        $this->putUrl($urls, $origin, '/', now(), 'daily', '1.00');

        foreach ($entries as $entry) {
            $path = $this->publicPath($entry);
            if (! $path) {
                continue;
            }

            $this->putUrl(
                $urls,
                $origin,
                $path,
                $entry->updated_at ?? $entry->published_at,
                $entry->type === 'article' || $entry->type === 'guide' ? 'monthly' : 'weekly',
                $entry->type === 'page' ? '0.80' : '0.70',
            );
        }

        if ($applicationSlug !== '') {
            $application = Application::query()->where('slug', $applicationSlug)->first();
            if ($application) {
                $this->appendApplicationDiscoveryUrls($urls, $origin, $application);
            }
        }

        return $urls->values();
    }

    private function normalizeOrigin(string $origin): string
    {
        $origin = rtrim($origin, '/');
        $host = parse_url($origin, PHP_URL_HOST);

        abort_unless(
            is_string($host) && ($host === 'petertecnet.com.br' || Str::endsWith($host, '.petertecnet.com.br')),
            422,
            'Origin não permitido.'
        );

        return $origin;
    }

    private function appendApplicationDiscoveryUrls(Collection $urls, string $origin, Application $application): void
    {
        $events = Event::query()
            ->where('app_id', $application->id)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($query) => $query->where('is_private', false)->orWhereNull('is_private'))
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->with([
                'production:id,slug,updated_at',
                'artists' => fn ($query) => $query
                    ->where('artists.app_id', $application->id)
                    ->where('artists.is_published', true)
                    ->select(['artists.id', 'artists.slug', 'artists.updated_at']),
            ])
            ->orderByDesc('updated_at')
            ->limit(self::MAX_EVENT_URLS)
            ->get([
                'id', 'app_id', 'production_id', 'slug', 'category', 'city', 'uf',
                'start_date', 'end_date', 'updated_at',
            ]);

        if ($events->isEmpty()) {
            return;
        }

        $latestUpdate = $events->max('updated_at') ?? now();
        foreach (['/event', '/eventos', '/productions', '/artists'] as $path) {
            $this->putUrl($urls, $origin, $path, $latestUpdate, 'daily', $path === '/eventos' ? '0.95' : '0.85');
        }

        $now = Carbon::now(config('app.timezone', 'America/Sao_Paulo'));

        foreach ($events as $event) {
            $lastmod = $event->updated_at ?? $latestUpdate;
            $this->putUrl($urls, $origin, '/event/' . rawurlencode((string) $event->slug), $lastmod, 'daily', '0.92');

            if ($event->production?->slug) {
                $this->putUrl(
                    $urls,
                    $origin,
                    '/production/' . rawurlencode((string) $event->production->slug) . '/public',
                    $event->production->updated_at ?? $lastmod,
                    'weekly',
                    '0.78',
                );
            }

            foreach ($event->artists as $artist) {
                if (! $artist->slug) {
                    continue;
                }

                $this->putUrl(
                    $urls,
                    $origin,
                    '/artist/' . rawurlencode((string) $artist->slug),
                    $artist->updated_at ?? $lastmod,
                    'weekly',
                    '0.76',
                );
            }

            $isDiscoverableNow = ! $event->end_date || Carbon::parse($event->end_date)->greaterThan($now);
            if (! $isDiscoverableNow || ! $event->city) {
                continue;
            }

            $citySlug = Str::slug((string) $event->city);
            if ($citySlug === '') {
                continue;
            }

            $cityBase = '/eventos/' . $citySlug;
            $this->putUrl($urls, $origin, $cityBase, $lastmod, 'daily', '0.90');
            $this->putUrl($urls, $origin, $cityBase . '/hoje', $lastmod, 'hourly', '0.94');
            $this->putUrl($urls, $origin, $cityBase . '/amanha', $lastmod, 'daily', '0.88');
            $this->putUrl($urls, $origin, $cityBase . '/fim-de-semana', $lastmod, 'daily', '0.90');
            $this->putUrl($urls, $origin, $cityBase . '/proximos-7-dias', $lastmod, 'daily', '0.84');

            if ($event->category) {
                $categorySlug = Str::slug((string) $event->category);
                if ($categorySlug !== '') {
                    $this->putUrl(
                        $urls,
                        $origin,
                        $cityBase . '/categoria/' . $categorySlug,
                        $lastmod,
                        'daily',
                        '0.86',
                    );
                }
            }
        }
    }

    private function putUrl(
        Collection $urls,
        string $origin,
        string $path,
        mixed $lastmod,
        string $changefreq,
        string $priority,
    ): void {
        $normalizedPath = $path === '/' ? '/' : '/' . ltrim($path, '/');
        $lastModified = $this->toAtomString($lastmod);
        $existing = $urls->get($normalizedPath);

        if (is_array($existing) && ($existing['lastmod'] ?? '') > ($lastModified ?? '')) {
            return;
        }

        $urls->put($normalizedPath, [
            'loc' => $origin . ($normalizedPath === '/' ? '/' : $normalizedPath),
            'lastmod' => $lastModified,
            'changefreq' => $changefreq,
            'priority' => $priority,
        ]);
    }

    private function toAtomString(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return $value instanceof Carbon
                ? $value->toAtomString()
                : Carbon::parse($value)->toAtomString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function publicPath(ContentEntry $entry): ?string
    {
        $metadata = is_array($entry->metadata) ? $entry->metadata : [];
        $configured = trim((string) ($metadata['public_path'] ?? ''));
        if ($configured !== '') {
            return '/' . ltrim($configured, '/');
        }

        if ($entry->type === 'article' || $entry->type === 'guide') {
            return '/blog/' . rawurlencode((string) $entry->slug);
        }

        if ($entry->type === 'page' && mb_strtolower((string) $entry->category) === 'service') {
            return '/servicos/' . rawurlencode((string) $entry->slug);
        }

        if ($entry->type === 'case-study' && ! empty($metadata['standalone'])) {
            return '/portfolio/' . rawurlencode((string) $entry->slug);
        }

        return null;
    }
}
