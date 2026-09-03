<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Discovery\Services\DiscoveryService;
use App\Http\Controllers\Controller;
use App\Models\DiscoveryEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class DiscoveryEventController extends Controller
{
    private const EVENTS = [
        'page_view',
        'content_view',
        'category_view',
        'establishment_view',
        'product_view',
        'search',
        'cta_click',
        'outbound_click',
        'conversion',
    ];

    public function __construct(private readonly DiscoveryService $discovery)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'application' => ['nullable', 'string', 'max:120'],
            'session_id' => ['nullable', 'string', 'max:80'],
            'event_type' => ['required', 'string', 'in:' . implode(',', self::EVENTS)],
            'entity_type' => ['nullable', 'string', 'max:48'],
            'entity_id' => ['nullable', 'string', 'max:180'],
            'path' => ['nullable', 'string', 'max:500'],
            'source' => ['nullable', 'string', 'max:80'],
            'referrer' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $application = isset($data['application'])
            ? $this->discovery->resolveApplication($data['application'])
            : null;

        if (isset($data['application']) && ! $application) {
            abort(422, 'Aplicação inválida.');
        }

        $metadata = Arr::only($data['metadata'] ?? [], [
            'campaign',
            'medium',
            'term',
            'content',
            'category',
            'position',
            'destination',
            'device_class',
        ]);

        DiscoveryEvent::create([
            'application_id' => $application?->id,
            'session_id' => $data['session_id'] ?? null,
            'event_type' => $data['event_type'],
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'path' => $data['path'] ?? null,
            'source' => $data['source'] ?? null,
            'referrer_host' => $this->host($data['referrer'] ?? null),
            'metadata' => $metadata ?: null,
            'occurred_at' => now(),
        ]);

        return response()->json(['success' => true], 202);
    }

    private function host(?string $referrer): ?string
    {
        if (! $referrer) return null;
        $host = parse_url($referrer, PHP_URL_HOST);
        return is_string($host) ? mb_substr($host, 0, 255) : null;
    }
}
