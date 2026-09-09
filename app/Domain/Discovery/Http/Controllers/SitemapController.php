<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Discovery\Services\PublicSitemapService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SitemapController extends Controller
{
    public function __construct(
        private readonly PublicSitemapService $sitemap,
    ) {}

    public function show(Request $request): Response
    {
        $data = $request->validate([
            'origin' => ['nullable', 'url', 'max:500'],
            'application' => ['nullable', 'string', 'max:120'],
        ]);

        $origin = (string) ($data['origin'] ?? config('app.frontend_url', 'https://petertecnet.com.br'));
        $urls = $this->sitemap->build($origin, $data['application'] ?? null);
        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300, s-maxage=300',
            'X-Robots-Tag' => 'noindex, follow',
        ]);
    }
}
