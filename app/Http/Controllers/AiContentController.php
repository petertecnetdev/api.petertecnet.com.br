<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Production;
use App\Services\AiDescriptionService;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AiContentController extends Controller
{
    public function __construct(
        private readonly AiDescriptionService $descriptions,
        private readonly ApplicationContext $applicationContext,
    ) {
    }

    public function description(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'title' => ['nullable', 'string', 'max:200'],
            'current_description' => ['nullable', 'string', 'max:5000'],
            'context' => ['nullable', 'array', 'max:30'],
            'context.*' => ['nullable', 'string', 'max:500'],
            'locale' => ['nullable', 'string', 'max:10'],
            'tone' => ['nullable', 'string', 'max:160'],
        ]);

        $title = trim((string) ($data['title'] ?? ''));
        $currentDescription = trim((string) ($data['current_description'] ?? ''));
        $context = array_filter(
            (array) ($data['context'] ?? []),
            static fn ($value) => trim((string) $value) !== '',
        );

        if ($title === '' && $currentDescription === '' && $context === []) {
            throw ValidationException::withMessages([
                'title' => ['Informe ao menos um nome, uma descrição atual ou algum contexto para a IA.'],
            ]);
        }

        $data['context'] = $this->enrichContext($data['entity_type'], $context, $request);

        try {
            $result = $this->descriptions->generateDescription(
                $data,
                $request->user('api')?->getAuthIdentifier(),
            );

            return response()->json([
                'description' => $result['description'],
                'mode' => $result['mode'],
                'meta' => [
                    'model' => $result['model'],
                    'usage' => $result['usage'],
                ],
            ]);
        } catch (RuntimeException $exception) {
            $configurationError = str_contains(
                mb_strtolower($exception->getMessage()),
                'não está configurado',
            );

            Log::warning('Falha no endpoint de descrição com IA.', [
                'user_id' => $request->user('api')?->getAuthIdentifier(),
                'entity_type' => $data['entity_type'],
                'configured' => $this->descriptions->isConfigured(),
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $configurationError
                    ? 'A geração de descrições com IA está temporariamente indisponível.'
                    : $exception->getMessage(),
                'code' => $configurationError ? 'ai_not_configured' : 'ai_generation_failed',
            ], 503);
        }
    }

    private function enrichContext(string $entityType, array $context, Request $request): array
    {
        if ($entityType !== 'event') {
            return $context;
        }

        $user = $request->user('api') ?? $request->user();
        if (! $user) {
            return $context;
        }

        $eventId = (int) ($context['entityId'] ?? $context['eventId'] ?? $context['event_id'] ?? 0);
        $event = null;
        $production = null;

        if ($eventId > 0) {
            $event = Event::query()
                ->where('app_id', $this->applicationContext->id())
                ->with('production:id,app_id,user_id,name')
                ->find($eventId);
            $production = $event?->production;
        }

        if (! $production) {
            $productionId = (int) ($context['production_id'] ?? $context['productionId'] ?? 0);
            if ($productionId > 0) {
                $production = Production::query()
                    ->where('app_id', $this->applicationContext->id())
                    ->find($productionId);
            }
        }

        if (! $production) {
            return $context;
        }

        $isOwner = (int) $production->user_id === (int) $user->getAuthIdentifier();
        $isAdmin = method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');
        $isPlatformAdmin = strtolower(trim((string) $user->email)) === 'petertecnet@gmail.com';

        if (! $isOwner && ! $isAdmin && ! $isPlatformAdmin) {
            return $context;
        }

        if (! isset($context['production_name']) && $production->name) {
            $context['production_name'] = mb_substr((string) $production->name, 0, 500);
        }

        if ($event) {
            $context['event_start'] ??= $event->start_date?->format('Y-m-d H:i:s');
            $context['event_end'] ??= $event->end_date?->format('Y-m-d H:i:s');
            $context['venue'] ??= $event->venue ?: null;
            $context['city'] ??= $event->city ?: null;
            $context['uf'] ??= $event->uf ?: null;

            $ticketOptions = $event->tickets()
                ->where('quantity', '>', 0)
                ->orderBy('price')
                ->limit(4)
                ->get(['name', 'price'])
                ->map(function ($ticket) {
                    $price = (float) $ticket->price;
                    $priceLabel = $price <= 0
                        ? 'gratuito'
                        : 'R$ ' . number_format($price, 2, ',', '.');
                    return trim((string) $ticket->name) . ' — ' . $priceLabel;
                })
                ->filter()
                ->implode('; ');

            if ($ticketOptions !== '') {
                $context['ticket_options'] = mb_substr($ticketOptions, 0, 500);
            }
        }

        $referencesQuery = Event::query()
            ->where('app_id', $this->applicationContext->id())
            ->where('production_id', $production->id)
            ->whereNotNull('description')
            ->where('description', '!=', '');

        if ($event) {
            $referencesQuery->whereKeyNot($event->id);
        }

        $references = $referencesQuery
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get(['id', 'title', 'description', 'start_date']);

        foreach ($references as $index => $reference) {
            $referenceText = trim((string) $reference->description);
            if ($referenceText === '') {
                continue;
            }

            $context['historical_style_' . ($index + 1)] = mb_substr(
                'Título anterior: ' . trim((string) $reference->title) . "\n" .
                'Descrição anterior: ' . $referenceText,
                0,
                500,
            );
        }

        return array_slice($context, 0, 30, true);
    }

}
