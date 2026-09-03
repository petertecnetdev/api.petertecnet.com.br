<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    private const CACHE_KEY = 'public.applications.v1';

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        return response()->json([
            'applications' => Application::query()->orderBy('name')->get(),
            'available_capabilities' => config('platform.available_capabilities', []),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name']);
        $data['logo'] = $this->logo($data);
        $data['capabilities'] ??= [];
        $application = Application::query()->create($data);
        Cache::forget(self::CACHE_KEY);

        return response()->json(['application' => $application], 201);
    }

    public function update(Request $request, Application $application): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $this->validated($request, $application);
        if (isset($data['slug'])) $data['slug'] = $this->uniqueSlug($data['slug'], $application->id);
        $data['logo'] = $this->logo(array_merge($application->toArray(), $data));
        $application->update($data);
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
        abort_unless($request->user()?->hasPermission('application_manage'), 403, 'Usuário sem permissão para gerenciar aplicações.');
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
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string', 'distinct', Rule::in(config('platform.available_capabilities', []))],
            'version' => ['nullable', 'string', 'max:100'],
            'author' => ['nullable', 'string', 'max:255'],
            'release_date' => ['nullable', 'date'],
        ]);
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
        while (Application::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base . '-' . $number++;
        }

        return $slug;
    }
}
