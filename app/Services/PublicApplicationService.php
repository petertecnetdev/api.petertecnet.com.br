<?php

namespace App\Services;

use App\Models\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class PublicApplicationService
{
    private const CACHE_KEY = 'public.applications.v2';
    private const CACHE_TTL_SECONDS = 600;

    private const INTERNAL_APPLICATION_SLUGS = [
        'admin-center',
        'admin',
        'peter-tecnet',
        'petertecnet',
    ];

    public function index()
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn () => $this->publicApplicationsQuery()
                ->select($this->publicFields())
                ->orderByDesc('is_default')
                ->orderBy('launcher_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function findBySlug(string $slug): Application
    {
        return $this->publicApplicationsQuery()
            ->where('slug', $slug)
            ->select($this->publicFields())
            ->firstOrFail();
    }

    private function publicApplicationsQuery(): Builder
    {
        return Application::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->whereNotIn('slug', self::INTERNAL_APPLICATION_SLUGS)
            ->where(function (Builder $query) {
                $query
                    ->whereNull('url')
                    ->orWhere(function (Builder $urlQuery) {
                        $urlQuery
                            ->where('url', 'not like', 'https://petertecnet.com.br%')
                            ->where('url', 'not like', 'http://petertecnet.com.br%');
                    });
            });
    }

    private function publicFields(): array
    {
        return [
            'id', 'name', 'description', 'slug', 'url', 'logo', 'version',
            'author', 'release_date', 'category', 'launcher_order', 'is_default',
            'operational_status', 'maintenance_message',
        ];
    }
}
