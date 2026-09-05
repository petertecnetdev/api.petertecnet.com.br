<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstablishmentEventController extends Controller
{
    public function index(Request $request, Establishment $establishment): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
        ]);

        $events = Event::query()
            ->where('production_id', $establishment->id)
            ->when(
                isset($data['app_id']),
                fn ($query) => $query->where('app_id', (int) $data['app_id'])
            )
            ->withCount(['tickets', 'artists'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get([
                'id',
                'app_id',
                'production_id',
                'title',
                'slug',
                'start_date',
                'end_date',
                'venue',
                'city',
                'uf',
                'is_published',
                'is_approved',
                'is_cancelled',
                'is_private',
            ]);

        return response()->json([
            'establishment' => [
                'id' => $establishment->id,
                'name' => $establishment->name,
                'fantasy' => $establishment->fantasy,
                'slug' => $establishment->slug,
                'city' => $establishment->city,
                'uf' => $establishment->uf,
            ],
            'events' => $events,
        ]);
    }
}
