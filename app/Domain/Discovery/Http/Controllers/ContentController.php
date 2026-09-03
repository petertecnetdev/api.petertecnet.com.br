<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Discovery\Services\DiscoveryService;
use App\Http\Controllers\Controller;
use App\Models\ContentEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    public function __construct(private readonly DiscoveryService $discovery)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'application' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', 'string', 'max:120'],
            'cluster' => ['nullable', 'string', 'max:140'],
            'tag' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:160'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = ContentEntry::query()
            ->with(['application:id,name,slug', 'establishment:id,name,fantasy,slug,city,uf', 'author:id,first_name,last_name,user_name'])
            ->published()
            ->forApplication($data['application'] ?? null);

        if (! empty($data['type'])) $query->where('type', $data['type']);
        if (! empty($data['category'])) $query->where('category', $data['category']);
        if (! empty($data['cluster'])) $query->where('cluster', $data['cluster']);
        if (! empty($data['tag'])) $query->whereJsonContains('tags', $data['tag']);
        if (! empty($data['q'])) {
            $term = '%' . trim($data['q']) . '%';
            $query->where(function (Builder $search) use ($term) {
                $search->where('title', 'like', $term)
                    ->orWhere('excerpt', 'like', $term)
                    ->orWhere('content', 'like', $term)
                    ->orWhere('search_intent', 'like', $term);
            });
        }

        $result = $query->orderByDesc('published_at')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 18));
        $result->getCollection()->transform(fn (ContentEntry $entry) => $this->payload($entry, false));

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $application = $request->query('application');
        $entry = ContentEntry::query()
            ->with(['application:id,name,slug', 'establishment:id,name,fantasy,slug,city,uf', 'author:id,first_name,last_name,user_name'])
            ->published()
            ->forApplication(is_string($application) ? $application : null)
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->payload($entry, true),
        ]);
    }

    private function payload(ContentEntry $entry, bool $withRelated): array
    {
        $payload = $entry->toArray();
        $payload['seo'] = $this->discovery->contentSeo($entry);

        if ($withRelated) {
            $payload['related_content'] = $this->discovery->relatedContent($entry)
                ->map(function (ContentEntry $related) {
                    $data = $related->only(['id', 'type', 'title', 'slug', 'excerpt', 'category', 'tags', 'cluster', 'published_at']);
                    $data['seo'] = $this->discovery->contentSeo($related);
                    return $data;
                })->values();
        }

        return $payload;
    }
}
