<?php

namespace App\Domain\Assets\Http\Controllers;

use App\Domain\Assets\Services\AssetAccessService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AssetInspectionController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AssetAccessService $access,
    ) {}

    public function compare(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'inspections');
        abort_unless($assetType === 'property', 422, 'Comparação de vistoria ainda não está registrada para este tipo de ativo.');

        $inspections = DB::table('property_inspections')
            ->where('app_id', $this->context->id())
            ->where('property_id', $assetId)
            ->orderByDesc('occurred_at')
            ->get();
        abort_if($inspections->count() < 2, 422, 'Registre pelo menos duas vistorias para comparar.');

        $left = $request->filled('left_id') ? $inspections->firstWhere('id', (int) $request->query('left_id')) : null;
        $right = $request->filled('right_id') ? $inspections->firstWhere('id', (int) $request->query('right_id')) : null;

        if (! $left) $left = $inspections->where('type', 'entry')->sortBy('occurred_at')->first() ?: $inspections->sortBy('occurred_at')->first();
        if (! $right) $right = $inspections->where('type', 'exit')->sortByDesc('occurred_at')->first() ?: $inspections->sortByDesc('occurred_at')->first();
        if ((int) $left->id === (int) $right->id) {
            $right = $inspections->first(fn ($inspection) => (int) $inspection->id !== (int) $left->id);
        }

        $spaces = DB::table('asset_spaces')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->whereNull('deleted_at')->pluck('name', 'id');
        $leftItems = $this->normalizeItems($left, $spaces);
        $rightItems = $this->normalizeItems($right, $spaces);
        $keys = collect(array_keys($leftItems))->merge(array_keys($rightItems))->unique()->values();

        $differences = $keys->map(function ($key) use ($leftItems, $rightItems) {
            $before = $leftItems[$key] ?? null; $after = $rightItems[$key] ?? null;
            $changed = ! $before || ! $after
                || ($before['condition'] ?? null) !== ($after['condition'] ?? null)
                || trim((string) ($before['notes'] ?? '')) !== trim((string) ($after['notes'] ?? ''));
            return [
                'key' => $key,
                'label' => $after['label'] ?? $before['label'] ?? $key,
                'space_id' => $after['space_id'] ?? $before['space_id'] ?? null,
                'space_name' => $after['space_name'] ?? $before['space_name'] ?? 'Geral',
                'before' => $before,
                'after' => $after,
                'changed' => $changed,
                'change_type' => ! $before ? 'added' : (! $after ? 'removed' : ($changed ? 'changed' : 'same')),
            ];
        })->sortBy(fn ($item) => ($item['space_name'] ?? '').'|'.($item['label'] ?? ''))->values();

        return response()->json([
            'left' => $this->inspectionPayload($left),
            'right' => $this->inspectionPayload($right),
            'differences' => $differences,
            'summary' => [
                'total_items' => $differences->count(),
                'changed' => $differences->where('changed', true)->count(),
                'unchanged' => $differences->where('changed', false)->count(),
                'added' => $differences->where('change_type', 'added')->count(),
                'removed' => $differences->where('change_type', 'removed')->count(),
            ],
            'photos' => [
                'left' => $this->inspectionFiles($assetType, $assetId, (int) $left->id),
                'right' => $this->inspectionFiles($assetType, $assetId, (int) $right->id),
            ],
        ]);
    }

    private function normalizeItems(object $inspection, $spaces): array
    {
        $items = $this->decode($inspection->items);
        $normalized = [];
        foreach ($items as $index => $item) {
            if (is_string($item)) $item = ['label' => $item, 'condition' => 'ok'];
            if (! is_array($item)) continue;
            $label = trim((string) ($item['label'] ?? $item['name'] ?? 'Item '.($index + 1)));
            $spaceId = isset($item['space_id']) && $item['space_id'] !== '' ? (int) $item['space_id'] : null;
            $spaceName = $item['space_name'] ?? ($spaceId ? ($spaces[$spaceId] ?? null) : null) ?? 'Geral';
            $key = mb_strtolower(trim($spaceName)).'|'.mb_strtolower($label);
            $normalized[$key] = [
                'label' => $label,
                'space_id' => $spaceId,
                'space_name' => $spaceName,
                'condition' => $item['condition'] ?? $item['status'] ?? 'not_recorded',
                'notes' => $item['notes'] ?? $item['description'] ?? null,
                'value' => $item['value'] ?? null,
                'metadata' => is_array($item['metadata'] ?? null) ? $item['metadata'] : [],
            ];
        }
        return $normalized;
    }

    private function inspectionPayload(object $row): array
    {
        $data = (array) $row; $data['items'] = $this->decode($row->items); $data['metadata'] = $this->decode($row->metadata); return $data;
    }

    private function inspectionFiles(string $assetType, int $assetId, int $inspectionId): array
    {
        return DB::table('files')->where('app_id', $this->context->id())->where('entity_name', $assetType)->where('entity_id', $assetId)->where('status', 'active')->orderBy('position')->get()->filter(function ($file) use ($inspectionId) {
            $meta = $this->decode($file->meta); return (int) ($meta['inspection_id'] ?? 0) === $inspectionId && str_starts_with((string) $file->mime_type, 'image/');
        })->map(function ($file) { $meta = $this->decode($file->meta); return ['id' => $file->id, 'original_name' => $file->original_name, 'caption' => $meta['caption'] ?? null, 'space_id' => $meta['space_id'] ?? null, 'before_after' => $meta['before_after'] ?? null]; })->values()->all();
    }

    private function decode(mixed $value): array { if (is_array($value)) return $value; if ($value === null || $value === '') return []; $d = json_decode((string) $value, true); return is_array($d) ? $d : []; }
}
