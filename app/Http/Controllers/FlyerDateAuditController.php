<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeFlyerDateConsistency;
use App\Models\Event;
use App\Models\FlyerDateAudit;
use App\Models\Production;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class FlyerDateAuditController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', Rule::in(['event', 'production'])],
            'entity_id' => ['required', 'integer', 'min:1'],
            'timezone' => ['required', 'timezone'],
            'locale' => ['nullable', 'string', 'max:16'],
            'recurring' => ['nullable', 'boolean'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
        ]);
        [$entity, $production, $image, $expected] = $this->entityContext($data['entity_type'], (int) $data['entity_id']);
        $this->authorizeManager($production, $request);
        $imageValue = trim((string) $image);
        $path = preg_match('#^https?://#i', $imageValue) ? null : (preg_replace('#^/?storage/#', '', ltrim($imageValue, '/')) ?: null);
        $imageVersion = $path && Storage::disk('public')->exists($path)
            ? ':'.Storage::disk('public')->size($path).':'.Storage::disk('public')->lastModified($path)
            : '';
        $fingerprint = hash('sha256', $imageValue.$imageVersion);
        $expectedKey = $expected ? sha1($expected->toIso8601String()) : 'none';

        $audit = FlyerDateAudit::query()->firstOrCreate([
            'app_id' => $this->context->id(),
            'entity_type' => $data['entity_type'],
            'entity_id' => (int) $entity->id,
            'image_fingerprint' => $fingerprint,
            'expected_start_key' => $expectedKey,
        ], [
            'user_id' => $request->user()->id,
            'timezone' => $data['timezone'],
            'locale' => $data['locale'] ?? 'en',
            'recurring' => (bool) ($data['recurring'] ?? false),
            'day_of_week' => $data['day_of_week'] ?? null,
            'status' => 'queued',
            'expected_start_at' => $expected,
        ]);

        if ($audit->wasRecentlyCreated || in_array($audit->status, ['failed', 'unavailable'], true)) {
            $audit->update(['status' => 'queued']);
            AnalyzeFlyerDateConsistency::dispatch($audit->id)->afterResponse();
        }

        return response()->json(['audit' => $audit->fresh()], $audit->wasRecentlyCreated ? 202 : 200);
    }

    public function show(Request $request, FlyerDateAudit $audit): JsonResponse
    {
        [, $production] = $this->entityContext($audit->entity_type, (int) $audit->entity_id);
        $this->authorizeManager($production, $request);
        abort_unless((int) $audit->app_id === $this->context->id(), 404);
        return response()->json(['audit' => $audit]);
    }

    public function review(Request $request, FlyerDateAudit $audit): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['corrected', 'ignored', 'review_requested'])]]);
        [, $production] = $this->entityContext($audit->entity_type, (int) $audit->entity_id);
        $this->authorizeManager($production, $request);
        abort_unless((int) $audit->app_id === $this->context->id(), 404);
        $audit->update(['review_action' => $data['action'], 'reviewed_at' => now()]);
        return response()->json(['audit' => $audit->fresh()]);
    }

    private function entityContext(string $type, int $id): array
    {
        if ($type === 'event') {
            $event = Event::query()->where('app_id', $this->context->id())->with('production')->findOrFail($id);
            return [$event, $event->production, $event->image, $event->start_date?->copy()];
        }
        $production = Production::query()->where('app_id', $this->context->id())->findOrFail($id);
        return [$production, $production, $production->background, null];
    }

    private function authorizeManager(?Production $production, Request $request): void
    {
        abort_unless($production, 404);
        $user = $request->user();
        $allowed = (int) $production->user_id === (int) $user?->id
            || (method_exists($user, 'hasProfile') && $user->hasProfile('Administrador'))
            || strtolower(trim((string) $user?->email)) === 'petertecnet@gmail.com';
        abort_unless($allowed, 403);
    }
}
