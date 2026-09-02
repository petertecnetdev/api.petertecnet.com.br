<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\Item;
use Illuminate\Http\Request;

class NexusShareController extends Controller
{
    public function catalog(Request $request, string $identifier)
    {
        $appId = $this->validatedAppId($request);

        $company = Establishment::query()
            ->forApplication($appId)
            ->where('is_cancelled', false)
            ->when(
                is_numeric($identifier),
                fn ($query) => $query->where('id', (int) $identifier),
                fn ($query) => $query->where('slug', $identifier)
            )
            ->with(['files' => fn ($query) => $query
                ->where('visibility', 'public')
                ->where('status', 'active')
                ->orderBy('position')])
            ->firstOrFail();

        $base = $this->nexusBaseUrl();
        $catalogUrl = $base . '/catalog/' . rawurlencode($company->slug);
        $title = trim((string) ($company->fantasy ?: $company->name ?: 'Catálogo Nexus'));
        $description = trim((string) ($company->description ?: "Confira o catálogo online de {$title} na Nexus."));
        $image = optional(
            $company->files->first(fn ($file) => $file->type === 'logo' && ! empty($file->public_url))
                ?: $company->files->first(fn ($file) => ! empty($file->public_url))
        )->public_url;

        return $this->shareDocument(
            url: $catalogUrl,
            title: $title . ' — Catálogo Nexus',
            description: $description,
            image: $image ?: $base . '/images/logo.png',
            type: 'website'
        );
    }

    public function item(Request $request, string $identifier)
    {
        $appId = $this->validatedAppId($request);

        $item = Item::query()
            ->where('entity_name', 'establishment')
            ->where('status', true)
            ->when(
                is_numeric($identifier),
                fn ($query) => $query->where('id', (int) $identifier),
                fn ($query) => $query->where('slug', $identifier)
            )
            ->with(['files' => fn ($query) => $query
                ->where('visibility', 'public')
                ->where('status', 'active')
                ->orderBy('position')])
            ->firstOrFail();

        $company = Establishment::query()
            ->forApplication($appId)
            ->where('is_cancelled', false)
            ->with(['files' => fn ($query) => $query
                ->where('visibility', 'public')
                ->where('status', 'active')
                ->orderBy('position')])
            ->findOrFail($item->entity_id);

        $base = $this->nexusBaseUrl();
        $itemUrl = $base . '/item/' . rawurlencode($item->slug);
        $companyTitle = trim((string) ($company->fantasy ?: $company->name ?: 'Nexus'));
        $itemTitle = trim((string) ($item->name ?: 'Item Nexus'));
        $description = trim((string) (
            $item->short_description
                ?: $item->description
                ?: "Confira {$itemTitle} de {$companyTitle} na Nexus."
        ));
        $image = optional(
            $item->files->first(fn ($file) => ! empty($file->public_url))
        )->public_url;
        $image = $image ?: optional(
            $company->files->first(fn ($file) => $file->type === 'logo' && ! empty($file->public_url))
                ?: $company->files->first(fn ($file) => ! empty($file->public_url))
        )->public_url;

        return $this->shareDocument(
            url: $itemUrl,
            title: $itemTitle . ' — ' . $companyTitle,
            description: $description,
            image: $image ?: $base . '/images/logo.png',
            type: 'product'
        );
    }

    private function validatedAppId(Request $request): int
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
        ]);

        return (int) $data['app_id'];
    }

    private function nexusBaseUrl(): string
    {
        return rtrim((string) config('peter.frontends.nexus', 'https://nexus.petertecnet.com.br'), '/');
    }

    private function shareDocument(string $url, string $title, string $description, string $image, string $type)
    {
        $esc = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeTitle = $esc($title);
        $safeDescription = $esc($description);
        $safeUrl = $esc($url);
        $safeImage = $esc($image);
        $safeType = $esc($type);
        $jsonUrl = $this->json($url);

        $html = <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$safeTitle}</title>
<meta name="description" content="{$safeDescription}">
<meta name="robots" content="index,follow,max-image-preview:large">
<link rel="canonical" href="{$safeUrl}">
<meta property="og:locale" content="pt_BR">
<meta property="og:type" content="{$safeType}">
<meta property="og:site_name" content="Nexus">
<meta property="og:title" content="{$safeTitle}">
<meta property="og:description" content="{$safeDescription}">
<meta property="og:url" content="{$safeUrl}">
<meta property="og:image" content="{$safeImage}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$safeTitle}">
<meta name="twitter:description" content="{$safeDescription}">
<meta name="twitter:image" content="{$safeImage}">
<meta http-equiv="refresh" content="0;url={$safeUrl}">
</head>
<body>
<p>Abrindo <a href="{$safeUrl}">{$safeTitle}</a>…</p>
<script>window.location.replace({$jsonUrl});</script>
</body>
</html>
HTML;

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    private function json(string $value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
    }
}
