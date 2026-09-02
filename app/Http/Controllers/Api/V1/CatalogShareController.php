<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Establishment;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CatalogShareController extends Controller
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function catalog(Request $request, string $identifier): Response
    {
        $application = $this->application($request);

        $company = Establishment::query()
            ->forApplication((int) $application->id)
            ->where('is_cancelled', false)
            ->where('is_published', true)
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

        $base = rtrim((string) $application->url, '/');
        abort_if($base === '', 422, 'O aplicativo não possui URL pública configurada.');

        $catalogUrl = $base . '/catalog/' . rawurlencode($company->slug);
        $appName = trim((string) ($application->name ?: $application->slug ?: 'Peter Tecnet'));
        $title = trim((string) ($company->fantasy ?: $company->name ?: 'Catálogo'));
        $description = trim((string) ($company->description ?: "Confira o catálogo online de {$title}."));
        $image = optional(
            $company->files->first(fn ($file) => $file->type === 'logo' && ! empty($file->public_url))
                ?: $company->files->first(fn ($file) => ! empty($file->public_url))
        )->public_url;
        $image = $image ?: $base . '/images/logo.png';

        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeTitle = $escape($title . ' — ' . $appName);
        $safeDescription = $escape($description);
        $safeUrl = $escape($catalogUrl);
        $safeImage = $escape($image);
        $safeAppName = $escape($appName);
        $jsonCatalogUrl = $this->json($catalogUrl);

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
<meta property="og:type" content="website">
<meta property="og:site_name" content="{$safeAppName}">
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
<script>window.location.replace({$jsonCatalogUrl});</script>
</body>
</html>
HTML;

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    private function application(Request $request): Application
    {
        if ($this->context->has()) {
            return $this->context->application();
        }

        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
        ]);

        return Application::query()->findOrFail((int) $data['app_id']);
    }

    private function json(string $value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
    }
}
