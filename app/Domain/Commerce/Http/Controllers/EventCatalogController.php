<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\EventCatalogService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class EventCatalogController extends Controller
{
    public function __construct(private readonly EventCatalogService $catalog) {}

    public function index(Request $request, int $eventId)
    {
        return response()->json($this->catalog->catalog($request->user(), $eventId));
    }

    public function sync(Request $request, int $eventId)
    {
        $data = $request->validate([
            'all_active' => ['sometimes', 'boolean'],
            'replace' => ['sometimes', 'boolean'],
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*.item_id' => ['required_with:items', 'integer', 'min:1'],
            'items.*.price' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999999.99'],
            'items.*.quantity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'items.*.is_active' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->catalog->sync($request->user(), $eventId, $data));
    }
}
