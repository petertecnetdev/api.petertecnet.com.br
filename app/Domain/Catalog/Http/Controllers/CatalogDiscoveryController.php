<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Services\CatalogDiscoveryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class CatalogDiscoveryController extends Controller
{
    public function __construct(private readonly CatalogDiscoveryService $discovery) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'target_city' => 'nullable|string|max:120',
            'target_uf' => 'nullable|string|size:2',
            'q' => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json($this->discovery->index($data));
    }

    public function search(Request $request)
    {
        $data = $request->validate([
            'q' => 'required|string|min:2|max:120',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        return response()->json($this->discovery->search($data));
    }
}
