<?php

namespace App\Services;

use App\Jobs\AnalyzeFlyerDateConsistency;
use App\Models\Event;
use App\Models\FlyerDateAudit;
use App\Models\Production;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\Storage;

final class FlyerDateAuditService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function queue(array $data, User $user): array
    {
        [$entity, $production, $image, $expected] = $this->entityContext($data['entity_type'], (int) $data['entity_id']);
        $this->authorizeManager($production, $user);
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
            'user_id' => $user->id,
            'timezone' => $data['timezone'],
            'locale' => $data['locale'] ?? 'en',
            'recurring' => (bool) ($data['recurring'] ?? false),
            'day_of_week' => $data['day_of_week'] ?? null,
            'status' => 'queued',
            'expected_start_at' => $expected,
        ]);

        $created = $audit->wasRecentlyCreated;
        if ($created || in_array($audit->status, ['failed', 'unavailable'], true)) {
            $audit->update(['status' => 'queued']);
            AnalyzeFlyerDateConsistency::dispatch($audit->id)->afterResponse();
        }
        return ['audit' => $audit->fresh(), 'created' => $created];
    }

    public function findForManager(int $auditId, User $user): FlyerDateAudit
    {
        $audit = FlyerDateAudit::query()->where('app_id', $this->context->id())->findOrFail($auditId);
        [, $production] = $this->entityContext($audit->entity_type, (int) $audit->entity_id);
        $this->authorizeManager($production, $user);
        return $audit;
    }

    public function review(int $auditId, string $action, User $user): FlyerDateAudit
    {
        $audit = $this->findForManager($auditId, $user);
        $audit->update(['review_action' => $action, 'reviewed_at' => now()]);
        return $audit->fresh();
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

    private function authorizeManager(?Production $production, User $user): void
    {
        abort_unless($production, 404);
        $allowed = (int) $production->user_id === (int) $user->id
            || (method_exists($user, 'hasProfile') && $user->hasProfile('Administrador'))
            || strtolower(trim((string) $user->email)) === 'petertecnet@gmail.com';
        abort_unless($allowed, 403);
    }
}
