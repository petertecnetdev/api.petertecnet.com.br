<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Discovery\Services\DiscoveryService;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Establishment;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DiscoveryController extends Controller
{
    public function __construct(private readonly DiscoveryService $discovery)
    {
    }

    public function categories(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $rows = $this->publicItems($application)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->selectRaw('category, COUNT(*) total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->category,
                'slug' => $this->discovery->categorySlug($row->category),
                'total' => (int) $row->total,
            ])->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function category(Request $request, string $slug): JsonResponse
    {
        $application = $this->application($request);
        $categories = $this->publicItems($application)
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->filter();

        $category = $categories->first(fn ($value) => Str::slug($value) === Str::slug($slug));
        abort_unless($category, 404);

        $items = $this->publicItems($application)
            ->with(['files', 'establishment' => fn ($query) => $query->select(['id', 'name', 'fantasy', 'slug', 'city', 'uf', 'category', 'type'])])
            ->where('category', $category)
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->limit(100)
            ->get();

        $items->each(function (Item $item) {
            $this->decorateItem($item);
            if ($item->establishment) {
                $item->setAttribute('seo', $this->discovery->itemSeo($item, $item->establishment));
            }
        });

        $locationNames = $items->pluck('establishment.city')->filter()->unique()->values();
        $description = $locationNames->isNotEmpty()
            ? "Encontre {$category} em " . $locationNames->take(3)->implode(', ') . ' no catálogo digital Peter Tecnet.'
            : "Encontre {$category}, compare opções e acesse os estabelecimentos no catálogo digital Peter Tecnet.";

        return response()->json([
            'success' => true,
            'data' => [
                'category' => ['name' => $category, 'slug' => Str::slug($category), 'total' => $items->count()],
                'seo' => [
                    'title' => Str::limit("{$category} | Catálogo Peter Tecnet", 68, ''),
                    'description' => Str::limit($description, 158, ''),
                    'canonical_path' => '/catalogo/' . Str::slug($category),
                    'og_image' => $this->discovery->socialImageUrl('category', Str::slug($category)),
                ],
                'items' => $items,
            ],
        ]);
    }

    public function establishment(Request $request, string $identifier): JsonResponse
    {
        $application = $this->application($request);
        $establishment = $this->discovery->publicEstablishment($identifier, $application);
        $items = Item::query()
            ->with('files')
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->where('status', true)
            ->orderByDesc('is_featured')
            ->orderBy('category')
            ->orderBy('name')
            ->limit(120)
            ->get();

        $items->each(function (Item $item) use ($establishment) {
            $this->decorateItem($item);
            $item->setAttribute('seo', $this->discovery->itemSeo($item, $establishment));
        });

        $payload = $establishment->toArray();
        $payload['seo'] = $this->discovery->establishmentSeo($establishment);
        $payload['items'] = $items;
        $payload['categories'] = $items->pluck('category')->filter()->countBy()->map(fn ($total, $name) => [
            'name' => $name,
            'slug' => Str::slug($name),
            'total' => $total,
        ])->values();

        return response()->json(['success' => true, 'data' => $payload]);
    }

    public function item(Request $request, string $identifier): JsonResponse
    {
        $application = $this->application($request);
        $item = $this->discovery->publicItem($identifier, $application);
        $establishment = $item->establishment()->firstOrFail();
        $establishment->setAppends([]);
        $this->decorateItem($item, 1280);
        $item->setAttribute('seo', $this->discovery->itemSeo($item, $establishment));

        $related = Item::query()
            ->with('files')
            ->where('status', true)
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->whereKeyNot($item->id)
            ->when($item->category, fn (Builder $query) => $query->where('category', $item->category))
            ->orderByDesc('is_featured')
            ->limit(8)
            ->get();
        $related->each(fn (Item $candidate) => $this->decorateItem($candidate));

        return response()->json([
            'success' => true,
            'data' => [
                'item' => $item,
                'establishment' => $establishment,
                'related_items' => $related,
            ],
        ]);
    }

    private function decorateItem(Item $item, int $width = 960): void
    {
        $item->setAppends(['image_url']);
        $files = $item->relationLoaded('files') ? $item->files : collect();
        $files->each(fn ($file) => $file->setAppends([]));
        $image = $files->first(fn ($file) => $file->is_primary && $file->isPublic() && ($file->type === 'image' || str_starts_with((string) $file->mime_type, 'image/')))
            ?? $files->first(fn ($file) => $file->isPublic() && ($file->type === 'image' || str_starts_with((string) $file->mime_type, 'image/')));
        if ($image?->uuid) {
            $url = rtrim((string) config('app.url'), '/') . '/api/v1/discovery/media/' . rawurlencode($image->uuid) . '?width=' . $width . '&format=auto&quality=78';
            $item->setAttribute('image_url', $url);
        }
    }

    private function publicItems(?Application $application): Builder
    {
        return Item::query()
            ->where('status', true)
            ->where('entity_name', 'establishment')
            ->whereHas('establishment', function (Builder $establishments) use ($application) {
                $establishments->where('is_cancelled', false)->where('is_published', true);
                if ($application) $establishments->forApplication($application->id);
            });
    }

    private function application(Request $request): ?Application
    {
        $identifier = $request->query('application');
        if (! is_string($identifier) || $identifier === '') return null;

        $application = $this->discovery->resolveApplication($identifier);
        abort_unless($application, 404, 'Aplicação não encontrada.');
        return $application;
    }
}
