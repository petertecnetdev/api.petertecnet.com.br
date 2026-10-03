<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AdminEcosystemResourceController extends Controller
{
    public function events(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'production_id' => ['nullable', 'integer', 'exists:establishments,id'],
            'status' => ['nullable', Rule::in(['published', 'draft', 'cancelled', 'upcoming', 'past'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = Event::query()
            ->with([
                'application:id,name,slug,logo',
                'production:id,name,fantasy,app_id,app_slug,user_id,city,uf',
            ]);

        if (! empty($data['app_id'])) {
            $query->where('app_id', (int) $data['app_id']);
        }
        if (! empty($data['production_id'])) {
            $query->where('production_id', (int) $data['production_id']);
        }
        if (! empty($data['from'])) {
            $query->where('start_date', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->where('start_date', '<=', date('Y-m-d 23:59:59', strtotime($data['to'])));
        }

        switch ($data['status'] ?? null) {
            case 'published':
                $query->where('is_published', true)->where(fn ($q) => $q->where('is_cancelled', false)->orWhereNull('is_cancelled'));
                break;
            case 'draft':
                $query->where('is_published', false);
                break;
            case 'cancelled':
                $query->where('is_cancelled', true);
                break;
            case 'upcoming':
                $query->where('start_date', '>=', now());
                break;
            case 'past':
                $query->where('end_date', '<', now());
                break;
        }

        if ($search = trim((string) ($data['search'] ?? ''))) {
            $query->where(function ($event) use ($search) {
                $event->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('venue', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhereHas('production', fn ($production) => $production
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('fantasy', 'like', "%{$search}%"));

                if (ctype_digit($search)) {
                    $event->orWhere('id', (int) $search);
                }
            });
        }

        $perPage = (int) ($data['per_page'] ?? 50);
        $paginator = $query->orderByDesc('start_date')->orderByDesc('id')->paginate($perPage);
        $events = collect($paginator->items())->map(function (Event $event) {
            return [
                'id' => $event->id,
                'app_id' => $event->app_id,
                'app_slug' => $event->app_slug,
                'application' => $event->application,
                'production_id' => $event->production_id,
                'production' => $event->production,
                'production_name' => $event->production?->fantasy ?: $event->production?->name,
                'establishment_name' => $event->production?->fantasy ?: $event->production?->name,
                'title' => $event->title,
                'slug' => $event->slug,
                'category' => $event->category,
                'image' => $event->image,
                'venue' => $event->venue,
                'city' => $event->city,
                'uf' => $event->uf,
                'start_date' => $event->start_date,
                'end_date' => $event->end_date,
                'is_published' => (bool) $event->is_published,
                'is_approved' => (bool) $event->is_approved,
                'is_cancelled' => (bool) $event->is_cancelled,
                'is_private' => (bool) $event->is_private,
                'temporal_status' => $event->temporal_status,
                'created_at' => $event->created_at,
                'updated_at' => $event->updated_at,
            ];
        })->values();

        return response()->json([
            'events' => $events,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
                'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            ],
        ]);
    }
}
