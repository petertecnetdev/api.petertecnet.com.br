<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Services\OwnedItemPaginationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OwnedItemPaginationController extends Controller
{
    public function __construct(private readonly OwnedItemPaginationService $items)
    {
    }

    public function index(Request $request, int $establishment): JsonResponse
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:190',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->items->paginate(
                (int) $request->user()->id,
                $establishment,
                $filters
            ),
        ]);
    }
}
