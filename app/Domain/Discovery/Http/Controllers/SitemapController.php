<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ContentEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class SitemapController extends Controller
{
    public function show(Request $request): Response
    {
        $data = $request->validate([
            'origin' => ['nullable', 'url', 'max:500'],
            'application' => ['nullable', 'string', 'max:120'],
        ]);

        $origin = rtrim((string) ($data['origin'] ?? config('app.frontend_url', 'https://petertecnet.com.br')), '/');
        $host = parse_url($origin, PHP_URL_HOST);
        abort_unless(is_string($host) && ($host === 'petertecnet.com.br' || Str::endsWith($host, '.petertecnet.com.br')), 422, 'Origin não permitido.');

        $entries = ContentEntry::query()
            ->published()
            ->forApplication($data['application'] ?? null)
            ->orderByDesc('updated_at')
            ->limit(5000)
            ->get(['id', 'type', 'title', 'slug', 'category', 'metadata', 'published_at', 'updated_at']);

        $urls = collect();
        foreach ($entries as $entry) {
            $path = $this->publicPath($entry);
            if (! $path) continue;

            $urls->put($path, [
                'loc' => $origin . $path,
                'lastmod' => optional($entry->updated_at ?? $entry->published_at)->toAtomString(),
                'changefreq' => $entry->type === 'article' || $entry->type === 'guide' ? 'monthly' : 'weekly',
                'priority' => $entry->type === 'page' ? '0.80' : '0.70',
            ]);
        }

        $xml = view('sitemap', ['urls' => $urls->values()])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300, s-maxage=300',
        ]);
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
