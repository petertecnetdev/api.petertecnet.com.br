<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Establishment;
use App\Models\Item;
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
        $company = $this->company($application, $identifier);
        $base = $this->frontend($application);
        $url = $base . '/catalog/' . rawurlencode($company->slug);
        $title = trim((string) ($company->fantasy ?: $company->name ?: 'Catálogo'));
        $description = trim((string) ($company->description ?: "Confira o catálogo online de {$title}."));
        $image = $this->companyImage($company, $base);

        return $this->html(
            application: $application,
            title: $title,
            description: $description,
            canonicalUrl: $url,
            image: $image,
            structuredData: [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => $title,
                'description' => $description,
                'url' => $url,
                'isPartOf' => ['@type' => 'WebSite', 'name' => $application->name, 'url' => $base],
            ],
        );
    }

    public function establishment(Request $request, string $identifier): Response
    {
        $application = $this->application($request);
        $company = $this->company($application, $identifier);
        $base = $this->frontend($application);
        $url = $base . '/establishment/view/' . rawurlencode($company->slug);
        $title = trim((string) ($company->fantasy ?: $company->name ?: 'Empresa'));
        $description = trim((string) ($company->description ?: "Conheça {$title}, seus produtos, serviços e formas de contato."));
        $image = $this->companyImage($company, $base);
        $sameAs = array_values(array_filter([
            $company->website_url,
            $company->facebook_url,
            $company->instagram_url,
            $company->twitter_url,
            $company->youtube_url,
        ]));

        $schema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $title,
            'description' => $description,
            'url' => $url,
            'image' => $image,
            'telephone' => $company->phone,
            'email' => $company->email,
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $company->address,
                'addressLocality' => $company->city,
                'addressRegion' => $company->uf,
                'postalCode' => $company->cep,
                'addressCountry' => 'BR',
            ]),
            'sameAs' => $sameAs ?: null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        return $this->html($application, $title, $description, $url, $image, $schema);
    }

    public function item(Request $request, string $identifier): Response
    {
        $application = $this->application($request);
        $item = Item::query()
            ->with(['files' => fn ($query) => $query->where('visibility', 'public')->where('status', 'active')->orderBy('position'), 'establishment'])
            ->where('entity_name', 'establishment')
            ->where('status', true)
            ->when(
                is_numeric($identifier),
                fn ($query) => $query->where('id', (int) $identifier),
                fn ($query) => $query->where('slug', $identifier)
            )
            ->whereIn('entity_id', function ($query) use ($application) {
                $query->select('establishments.id')
                    ->from('establishments')
                    ->where('is_cancelled', false)
                    ->where(function ($scope) use ($application) {
                        $scope->where('establishments.app_id', $application->id)
                            ->orWhereExists(function ($pivot) use ($application) {
                                $pivot->selectRaw('1')
                                    ->from('application_establishment')
                                    ->whereColumn('application_establishment.establishment_id', 'establishments.id')
                                    ->where('application_establishment.application_id', $application->id);
                            });
                    });
            })
            ->firstOrFail();

        $base = $this->frontend($application);
        $url = $base . '/item/view/' . rawurlencode($item->slug);
        $title = trim((string) ($item->name ?: 'Item'));
        $description = trim((string) ($item->description ?: "Veja detalhes de {$title}."));
        $image = optional($item->files->first(fn ($file) => ! empty($file->public_url)))->public_url
            ?: $item->image_url
            ?: $this->companyImage($item->establishment, $base);
        $isService = strtolower((string) $item->type) === 'service';
        $schema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => $isService ? 'Service' : 'Product',
            'name' => $title,
            'description' => $description,
            'url' => $url,
            'image' => $image,
            'category' => $item->category,
            'brand' => $item->brand ? ['@type' => 'Brand', 'name' => $item->brand] : null,
            'provider' => $item->establishment ? [
                '@type' => 'LocalBusiness',
                'name' => $item->establishment->fantasy ?: $item->establishment->name,
            ] : null,
            'offers' => ! $isService && $item->price !== null ? [
                '@type' => 'Offer',
                'priceCurrency' => 'BRL',
                'price' => (string) $item->price,
                'availability' => $item->status ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url' => $url,
            ] : null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        return $this->html($application, $title, $description, $url, $image, $schema);
    }

    private function company(Application $application, string $identifier): Establishment
    {
        return Establishment::query()
            ->forApplication((int) $application->id)
            ->where('is_cancelled', false)
            ->when(
                is_numeric($identifier),
                fn ($query) => $query->where('id', (int) $identifier),
                fn ($query) => $query->where('slug', $identifier)
            )
            ->with(['files' => fn ($query) => $query->where('visibility', 'public')->where('status', 'active')->orderBy('position')])
            ->firstOrFail();
    }

    private function frontend(Application $application): string
    {
        $configuredFrontend = config('peter.frontends.' . $application->slug);
        $base = rtrim((string) ($application->url ?: $configuredFrontend), '/');
        abort_if($base === '', 422, 'O aplicativo não possui URL pública configurada.');
        return $base;
    }

    private function companyImage(?Establishment $company, string $base): string
    {
        if (! $company) return $base . '/images/logo.png';
        $file = $company->files->first(fn ($candidate) => in_array($candidate->type, ['background', 'cover', 'banner'], true) && ! empty($candidate->public_url))
            ?: $company->files->first(fn ($candidate) => $candidate->type === 'logo' && ! empty($candidate->public_url))
            ?: $company->files->first(fn ($candidate) => ! empty($candidate->public_url));
        return $file?->public_url ?: $base . '/images/logo.png';
    }

    private function html(
        Application $application,
        string $title,
        string $description,
        string $canonicalUrl,
        string $image,
        array $structuredData
    ): Response {
        $appName = trim((string) ($application->name ?: $application->slug ?: 'Peter Tecnet'));
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeTitle = $escape($title . ' — ' . $appName);
        $safeDescription = $escape($description);
        $safeUrl = $escape($canonicalUrl);
        $safeImage = $escape($image);
        $safeAppName = $escape($appName);
        $jsonUrl = $this->json($canonicalUrl);
        $jsonLd = $this->json($structuredData);

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
<script type="application/ld+json">{$jsonLd}</script>
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

    private function application(Request $request): Application
    {
        if ($this->context->has()) return $this->context->application();
        $appId = (int) $request->validate(['app_id' => 'required|integer|exists:applications,id'])['app_id'];
        return Application::query()->findOrFail($appId);
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
