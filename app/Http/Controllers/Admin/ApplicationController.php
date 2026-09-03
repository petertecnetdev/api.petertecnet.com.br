<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    private const CACHE_KEY = 'public.applications.v1';

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        return response()->json([
            'applications' => Application::query()
                ->orderByDesc('is_default')
                ->orderBy('launcher_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name']);
        $data['logo'] = $this->logo($data);
        $data = $this->normalizeDefault($data);

        $application = DB::transaction(function () use ($data) {
            if (! empty($data['is_default'])) {
                Application::query()->where('is_default', true)->update(['is_default' => false]);
            }

            return Application::query()->create($data);
        });
        Cache::forget(self::CACHE_KEY);

        return response()->json(['application' => $application], 201);
    }

    public function update(Request $request, Application $application): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $this->validated($request, $application);
        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $application->id);
        }
        $data['logo'] = $this->logo(array_merge($application->toArray(), $data));
        $data = $this->normalizeDefault($data);

        DB::transaction(function () use ($application, $data) {
            if (! empty($data['is_default'])) {
                Application::query()
                    ->whereKeyNot($application->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
            $application->update($data);
        });
        Cache::forget(self::CACHE_KEY);

        return response()->json(['application' => $application->fresh()]);
    }

    public function destroy(Request $request, Application $application): JsonResponse
    {
        $this->authorizeAccess($request);
        $application->delete();
        Cache::forget(self::CACHE_KEY);

        return response()->json(null, 204);
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless(
            $request->user()?->hasPermission('application_manage'),
            403,
            'Usuário sem permissão para gerenciar aplicações.'
        );
    }

    private function validated(Request $request, ?Application $application = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('applications', 'slug')->ignore($application?->id)],
            'url' => ['required', 'url', 'max:2048'],
            'logo' => ['nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'self_service_access' => ['sometimes', 'boolean'],
            'launcher_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_visible' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'operational_status' => ['sometimes', Rule::in(['operational', 'degraded', 'maintenance', 'down'])],
            'maintenance_message' => ['nullable', 'string', 'max:500'],
            'ecosystem_sdk_version' => ['nullable', 'string', 'max:30'],
            'version' => ['nullable', 'string', 'max:100'],
            'author' => ['nullable', 'string', 'max:255'],
            'release_date' => ['nullable', 'date'],
        ]);
    }

    private function normalizeDefault(array $data): array
    {
        if (! empty($data['is_default'])) {
            $data['is_active'] = true;
            $data['is_visible'] = true;
            $data['launcher_order'] = 0;
        }

        return $data;
    }

    private function logo(array $data): string
    {
        return $data['logo'] ?: rtrim($data['url'], '/') . '/logo';
    }

    private function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'aplicacao';
        $slug = $base;
        $number = 2;

        while (Application::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base . '-' . $number++;
        }

        return $slug;
    }
}
