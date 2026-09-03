<?php

namespace App\Domain\Discovery\Services;

use App\Domain\Catalog\Services\PublicCatalogQuery;
use App\Models\Application;
use App\Models\ContentEntry;
use App\Models\Establishment;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DiscoveryService
{
    public function __construct(private readonly PublicCatalogQuery $publicCatalog)
    {
    }

    public function resolveApplication(int|string|null $identifier): ?Application
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        return Application::query()
            ->where('is_active', true)
            ->when(is_numeric($identifier), fn (Builder $query) => $query->whereKey((int) $identifier))
            ->when(! is_numeric($identifier), fn (Builder $query) => $query->where('slug', (string) $identifier))
            ->first();
    }

    public function publicEstablishment(string $identifier, ?Application $application = null): Establishment
    {
        $query = $application
            ? $this->publicCatalog->establishments($application)
            : Establishment::query()
                ->with('applications:id,slug,is_active')
                ->where('is_cancelled', false)
                ->where('is_published', true);

        $establishment = $query
            ->where(function (Builder $lookup) use ($identifier) {
                $lookup->where('slug', $identifier);
                if (is_numeric($identifier)) {
                    $lookup->orWhereKey((int) $identifier);
                }
            })
            ->firstOrFail();

        if (! $application) {
            $activeApplications = Application::query()
                ->where('is_active', true)
                ->get()
                ->keyBy(fn (Application $candidate) => (int) $candidate->id);

            abort_unless(
                $this->publicCatalog->publicApplicationIds($establishment, $activeApplications) !== [],
                404
            );
        }

        $establishment->setAppends([]);

        return $establishment;
    }

    public function publicItem(string $identifier, ?Application $application = null): Item
    {
        $query = $application
            ? $this->publicCatalog->items($application)
            : Item::query()
                ->where('status', true)
                ->where('entity_name', 'establishment')
                ->whereHas('establishment', fn (Builder $establishments) => $establishments
                    ->where('is_cancelled', false)
                    ->where('is_published', true));

        $item = $query
            ->with(['files', 'establishment.applications:id,slug,is_active'])
            ->where(function (Builder $lookup) use ($identifier) {
                $lookup->where('slug', $identifier);
                if (is_numeric($identifier)) {
                    $lookup->orWhereKey((int) $identifier);
                }
            })
            ->firstOrFail();

        if (! $application) {
            $establishment = $item->establishment;
            abort_unless($establishment, 404);

            $activeApplications = Application::query()
                ->where('is_active', true)
                ->get()
                ->keyBy(fn (Application $candidate) => (int) $candidate->id);

            abort_unless(
                $this->publicCatalog->publicApplicationIds($establishment, $activeApplications) !== [],
                404
            );
        }

        $item->setAppends(['image_url']);

        return $item;
    }

    public function establishmentSeo(Establishment $establishment): array
    {
        $name = $establishment->fantasy ?: $establishment->name;
        $location = $this->locationLabel($establishment);
        $category = trim((string) ($establishment->category ?: $establishment->type));
        $suffix = $location ? " em {$location}" : '';
        $description = trim((string) $establishment->description);

        return [
            'title' => $this->limit("{$name}{$suffix} | Peter Tecnet", 68),
            'description' => $this->limit($description ?: "Conheça {$name}{$suffix}. Veja produtos, serviços, informações e formas de contato no ecossistema Peter Tecnet.", 158),
            'canonical_path' => '/empresas/' . rawurlencode($establishment->slug),
            'keywords' => array_values(array_filter([$name, $category, $establishment->city, $establishment->uf, 'Peter Tecnet'])),
            'og_image' => $this->socialImageUrl('establishment', $establishment->slug),
            'location' => $location,
        ];
    }

    public function itemSeo(Item $item, Establishment $establishment): array
    {
        $company = $establishment->fantasy ?: $establishment->name;
        $location = $this->locationLabel($establishment);
        $category = trim((string) ($item->category ?: $item->type ?: 'Produto'));
        $localPart = $location ? " em {$location}" : '';
        $companyPart = $company ? " | {$company}" : ' | Peter Tecnet';
        $description = trim((string) $item->description);

        return [
            'title' => $this->limit("{$item->name}{$localPart}{$companyPart}", 68),
            'description' => $this->limit($description ?: "{$item->name}: {$category} disponível em {$company}. Consulte detalhes, disponibilidade e informações no catálogo digital.", 158),
            'canonical_path' => '/solucoes/' . rawurlencode($item->slug ?: (string) $item->id),
            'keywords' => array_values(array_filter([$item->name, $category, $item->subcategory, $item->brand, $company, $establishment->city, $establishment->uf])),
            'og_image' => $this->socialImageUrl('item', $item->slug ?: (string) $item->id),
            'location' => $location,
        ];
    }

    public function contentSeo(ContentEntry $entry): array
    {
        return [
            'title' => $this->limit($entry->seo_title ?: "{$entry->title} | Peter Tecnet", 68),
            'description' => $this->limit($entry->seo_description ?: $entry->excerpt ?: Str::of(strip_tags((string) $entry->content))->squish()->limit(155, '')->toString(), 158),
            'canonical_path' => $entry->canonical_url ?: '/blog/' . rawurlencode($entry->slug),
            'keywords' => array_values(array_unique(array_filter(array_merge([$entry->category, $entry->cluster, $entry->search_intent], $entry->tags ?: [])))),
            'og_image' => $entry->og_image ?: $this->socialImageUrl('content', $entry->slug),
        ];
    }

    public function relatedContent(ContentEntry $entry, int $limit = 4): Collection
    {
        $tags = array_values(array_filter($entry->tags ?: []));

        return ContentEntry::query()
            ->published()
            ->whereKeyNot($entry->id)
            ->when($entry->application_id, function (Builder $query) use ($entry) {
                $query->where(function (Builder $apps) use ($entry) {
                    $apps->whereNull('application_id')->orWhere('application_id', $entry->application_id);
                });
            })
            ->where(function (Builder $related) use ($entry, $tags) {
                if ($entry->cluster) {
                    $related->where('cluster', $entry->cluster);
                }
                if ($entry->category) {
                    $related->orWhere('category', $entry->category);
                }
                foreach ($tags as $tag) {
                    $related->orWhereJsonContains('tags', $tag);
                }
            })
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }

    public function categorySlug(string $value): string
    {
        return Str::slug($value) ?: 'geral';
    }

    public function socialImageUrl(string $type, string $identifier): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/v1/discovery/social-card/' . rawurlencode($type) . '/' . rawurlencode($identifier) . '.png';
    }

    private function locationLabel(Establishment $establishment): string
    {
        return trim(implode(' - ', array_filter([$establishment->city, $establishment->uf])));
    }

    private function limit(string $value, int $length): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $value) ?: $value), $length, '');
    }
}
