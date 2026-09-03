<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Discovery\Services\DiscoveryService;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ContentEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContentManagementController extends Controller
{
    public function __construct(private readonly DiscoveryService $discovery)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $applicationIds = $this->allowedApplicationIds($actor);

        $query = ContentEntry::query()->with(['application:id,name,slug', 'author:id,first_name,last_name,user_name']);
        if ($applicationIds !== null) {
            $query->where(fn ($scope) => $scope->whereNull('application_id')->orWhereIn('application_id', $applicationIds));
        }
        if ($request->filled('application_id')) {
            $applicationId = (int) $request->query('application_id');
            $this->guardApplication($actor, $applicationId);
            $query->where('application_id', $applicationId);
        }
        if ($request->filled('status')) $query->where('status', $request->query('status'));
        if ($request->filled('type')) $query->where('type', $request->query('type'));
        if ($request->filled('q')) {
            $term = '%' . trim((string) $request->query('q')) . '%';
            $query->where(fn ($search) => $search->where('title', 'like', $term)->orWhere('excerpt', 'like', $term)->orWhere('category', 'like', $term));
        }

        $entries = $query->latest('updated_at')->limit(300)->get();
        $entries->each(fn (ContentEntry $entry) => $entry->setAttribute('seo', $this->discovery->contentSeo($entry)));

        return response()->json([
            'success' => true,
            'data' => [
                'entries' => $entries,
                'applications' => $this->applicationsFor($actor),
                'summary' => [
                    'total' => $entries->count(),
                    'published' => $entries->where('status', 'published')->count(),
                    'scheduled' => $entries->where('status', 'scheduled')->count(),
                    'drafts' => $entries->where('status', 'draft')->count(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $data = $this->validated($request);
        $applicationId = isset($data['application_id']) ? (int) $data['application_id'] : null;
        if ($applicationId) $this->guardApplication($actor, $applicationId);

        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['title'], $applicationId, $data['type'] ?? 'article');
        $data['author_user_id'] = $actor->id;
        $this->normalizeLifecycle($data);

        $entry = ContentEntry::create($data)->fresh(['application:id,name,slug', 'author:id,first_name,last_name,user_name']);
        $entry->setAttribute('seo', $this->discovery->contentSeo($entry));

        return response()->json(['success' => true, 'data' => $entry], 201);
    }

    public function update(Request $request, ContentEntry $content): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $this->guardExisting($actor, $content);
        $data = $this->validated($request, $content);
        $applicationId = array_key_exists('application_id', $data) ? ($data['application_id'] ? (int) $data['application_id'] : null) : $content->application_id;
        if ($applicationId) $this->guardApplication($actor, $applicationId);

        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $applicationId, $data['type'] ?? $content->type, $content->id);
        }
        $this->normalizeLifecycle($data, $content);
        $content->fill($data)->save();
        $content->refresh()->load(['application:id,name,slug', 'author:id,first_name,last_name,user_name']);
        $content->setAttribute('seo', $this->discovery->contentSeo($content));

        return response()->json(['success' => true, 'data' => $content]);
    }

    public function publish(Request $request, ContentEntry $content): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $this->guardExisting($actor, $content);
        $content->update(['status' => 'published', 'published_at' => now(), 'scheduled_at' => null]);

        return response()->json(['success' => true, 'data' => $content->fresh()]);
    }

    public function destroy(Request $request, ContentEntry $content): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $this->guardExisting($actor, $content);
        $content->delete();

        return response()->json(['success' => true]);
    }

    private function validated(Request $request, ?ContentEntry $content = null): array
    {
        return $request->validate([
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'establishment_id' => ['nullable', 'integer', 'exists:establishments,id'],
            'type' => ['sometimes', 'string', Rule::in(['article', 'guide', 'page', 'case-study'])],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'scheduled', 'published', 'archived'])],
            'title' => [$content ? 'sometimes' : 'required', 'string', 'max:220'],
            'slug' => ['nullable', 'string', 'max:220'],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'content' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:120'],
            'cluster' => ['nullable', 'string', 'max:140'],
            'search_intent' => ['nullable', 'string', 'max:180'],
            'cover_image' => ['nullable', 'string', 'max:500'],
            'og_image' => ['nullable', 'string', 'max:500'],
            'seo_title' => ['nullable', 'string', 'max:220'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'string', 'max:500'],
            'related' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'scheduled_at' => ['nullable', 'date'],
            'published_at' => ['nullable', 'date'],
        ]);
    }

    private function normalizeLifecycle(array &$data, ?ContentEntry $current = null): void
    {
        $status = $data['status'] ?? $current?->status ?? 'draft';
        if ($status === 'published' && empty($data['published_at']) && ! $current?->published_at) {
            $data['published_at'] = now();
        }
        if ($status === 'scheduled' && empty($data['scheduled_at']) && ! $current?->scheduled_at) {
            abort(422, 'scheduled_at é obrigatório para conteúdo agendado.');
        }
        if ($status !== 'scheduled' && array_key_exists('scheduled_at', $data) && empty($data['scheduled_at'])) {
            $data['scheduled_at'] = null;
        }
    }

    private function uniqueSlug(string $value, ?int $applicationId, string $type, ?int $ignore = null): string
    {
        $base = Str::slug($value) ?: 'conteudo';
        $slug = $base;
        $counter = 2;

        while (ContentEntry::query()
            ->when($ignore, fn ($query) => $query->where('id', '!=', $ignore))
            ->where('type', $type)
            ->where('application_id', $applicationId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }

    private function authorizeActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor && ($actor->hasProfile('Administrador') || $actor->hasPermission('content_manage')), 403);
        return $actor;
    }

    private function guardExisting(User $actor, ContentEntry $content): void
    {
        if ($content->application_id) $this->guardApplication($actor, (int) $content->application_id);
    }

    private function guardApplication(User $actor, int $applicationId): void
    {
        abort_unless(Application::whereKey($applicationId)->exists(), 404);
        if ($actor->hasProfile('Administrador')) return;
        abort_unless($actor->applications()->whereKey($applicationId)->exists(), 403, 'Aplicação fora do seu escopo.');
    }

    private function allowedApplicationIds(User $actor): ?array
    {
        return $actor->hasProfile('Administrador') ? null : $actor->applications()->pluck('applications.id')->map(fn ($id) => (int) $id)->all();
    }

    private function applicationsFor(User $actor)
    {
        $query = Application::query()->select(['id', 'name', 'slug', 'url'])->orderBy('name');
        if (! $actor->hasProfile('Administrador')) {
            $query->whereIn('id', $this->allowedApplicationIds($actor) ?? []);
        }
        return $query->get();
    }
}