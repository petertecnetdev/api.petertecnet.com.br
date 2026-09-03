<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationBrandingRevision;
use App\Services\ApplicationBrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApplicationBrandingController extends Controller
{
    private const PUBLIC_APPLICATIONS_CACHE_KEY = 'public.applications.v1';

    public function __construct(private readonly ApplicationBrandingService $branding) {}

    public function show(Request $request, Application $application): JsonResponse
    {
        $this->authorizeAccess($request);

        return response()->json($this->adminPayload($application));
    }

    public function updateDraft(Request $request, Application $application): JsonResponse
    {
        $this->authorizeAccess($request);
        $validated = $this->validatedBranding($request);
        $current = $application->branding_draft ?? $application->branding ?? [];
        $draft = array_merge($current, $this->branding->editable($validated));

        $application->forceFill([
            'branding_draft' => $draft,
            'branding_updated_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Rascunho da identidade visual salvo.',
            ...$this->adminPayload($application->fresh()),
        ]);
    }

    public function uploadAsset(Request $request, Application $application): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'asset' => ['required', Rule::in(ApplicationBrandingService::ASSET_FIELDS)],
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $uploaded = $request->file('file');
        $extension = strtolower((string) $uploaded->getClientOriginalExtension());
        $filename = $this->branding->assetFilename($application, $data['asset'], $extension);

        // Each upload receives its own directory so the public URL changes immediately,
        // while the actual filename remains predictable (e.g. nexus-logo.png).
        // This avoids browsers/CDNs reusing the previous logo after a new publication
        // and keeps historical branding revisions rollback-safe.
        $path = $uploaded->storeAs(
            'branding/' . $application->slug . '/' . $data['asset'] . '/' . Str::uuid(),
            $filename,
            'public'
        );
        $storedUrl = Storage::disk('public')->url($path);
        $url = Str::startsWith($storedUrl, ['http://', 'https://']) ? $storedUrl : url($storedUrl);
        $draft = $application->branding_draft ?? $application->branding ?? [];
        $draft[$data['asset']] = $url;

        $application->forceFill([
            'branding_draft' => $draft,
            'branding_updated_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Imagem adicionada ao rascunho.',
            'asset' => $data['asset'],
            'filename' => $filename,
            'url' => $url,
            ...$this->adminPayload($application->fresh()),
        ], 201);
    }

    public function publish(Request $request, Application $application): JsonResponse
    {
        $this->authorizeAccess($request);

        if (! is_array($application->branding_draft)) {
            return response()->json([
                'message' => 'Salve um rascunho antes de publicar.',
            ], 422);
        }

        $application = DB::transaction(function () use ($request, $application) {
            $application->refresh();
            $nextVersion = ((int) $application->branding_version) + 1;
            $published = $this->branding->editable($application->branding_draft);

            ApplicationBrandingRevision::query()->create([
                'application_id' => $application->id,
                'version' => $nextVersion,
                'branding' => $published,
                'created_by' => $request->user()?->id,
                'created_at' => now(),
            ]);

            $updates = [
                'branding' => $published,
                'branding_draft' => null,
                'branding_version' => $nextVersion,
                'branding_updated_at' => now(),
                'branding_published_at' => now(),
            ];

            if (! empty($published['logo'])) {
                $updates['logo'] = $published['logo'];
            }

            $application->forceFill($updates)->save();

            return $application->fresh();
        });

        Cache::forget(self::PUBLIC_APPLICATIONS_CACHE_KEY);

        return response()->json([
            'message' => 'Identidade visual publicada para o ecossistema.',
            ...$this->adminPayload($application),
        ]);
    }

    public function restore(
        Request $request,
        Application $application,
        ApplicationBrandingRevision $revision
    ): JsonResponse {
        $this->authorizeAccess($request);
        abort_unless((int) $revision->application_id === (int) $application->id, 404);

        $application->forceFill([
            'branding_draft' => $revision->branding,
            'branding_updated_at' => now(),
        ])->save();

        return response()->json([
            'message' => "Versão {$revision->version} restaurada como rascunho. Revise e publique para aplicá-la.",
            ...$this->adminPayload($application->fresh()),
        ]);
    }

    public function discardDraft(Request $request, Application $application): JsonResponse
    {
        $this->authorizeAccess($request);

        $application->forceFill([
            'branding_draft' => null,
            'branding_updated_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Rascunho descartado.',
            ...$this->adminPayload($application->fresh()),
        ]);
    }

    private function validatedBranding(Request $request): array
    {
        return $request->validate([
            'display_name' => ['nullable', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:80'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'logo' => ['nullable', 'url:http,https', 'max:2048'],
            'logo_light' => ['nullable', 'url:http,https', 'max:2048'],
            'logo_dark' => ['nullable', 'url:http,https', 'max:2048'],
            'icon' => ['nullable', 'url:http,https', 'max:2048'],
            'favicon' => ['nullable', 'url:http,https', 'max:2048'],
            'social_image' => ['nullable', 'url:http,https', 'max:2048'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
    }

    private function adminPayload(Application $application): array
    {
        return [
            'application' => [
                'id' => $application->id,
                'slug' => $application->slug,
                'name' => $application->name,
                'url' => $application->url,
            ],
            'published' => $this->branding->publicPayload($application),
            'draft' => is_array($application->branding_draft)
                ? $this->branding->draft($application)
                : null,
            'history' => $application->brandingRevisions()
                ->with('author:id,first_name,last_name,email')
                ->latest('version')
                ->limit(20)
                ->get()
                ->map(fn (ApplicationBrandingRevision $revision) => [
                    'id' => $revision->id,
                    'version' => $revision->version,
                    'branding' => $revision->branding,
                    'created_at' => $revision->created_at?->toIso8601String(),
                    'created_by' => $revision->author ? [
                        'id' => $revision->author->id,
                        'name' => trim(($revision->author->first_name ?? '') . ' ' . ($revision->author->last_name ?? '')) ?: $revision->author->email,
                    ] : null,
                ])
                ->values(),
        ];
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless(
            $request->user()?->hasPermission('application_manage'),
            403,
            'Usuário sem permissão para gerenciar a identidade visual das aplicações.'
        );
    }
}
