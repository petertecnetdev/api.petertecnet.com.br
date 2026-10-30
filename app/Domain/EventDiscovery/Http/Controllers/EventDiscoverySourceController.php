<?php

namespace App\Domain\EventDiscovery\Http\Controllers;

use App\Domain\EventDiscovery\Services\DiscoverySourceRegistry;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventDiscoverySourceController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly DiscoverySourceRegistry $sources,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->sources->listForApplication($this->context->id()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'url' => ['required', 'url:https', 'max:2048'],
            'city' => ['nullable', 'string', 'max:160'],
            'uf' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'authorization_confirmed' => ['required', 'accepted'],
        ]);
        $source = $this->sources->register(
            $this->context->id(), $input, (int) $request->user('api')->getKey()
        );
        return response()->json(['success' => true, 'data' => $source], 201);
    }

    public function update(Request $request, int $sourceId): JsonResponse
    {
        $input = $request->validate(['enabled' => ['required', 'boolean']]);
        $source = $this->sources->setEnabled(
            $this->context->id(), $sourceId, (bool) $input['enabled']
        );
        return response()->json(['success' => true, 'data' => $source]);
    }
}
