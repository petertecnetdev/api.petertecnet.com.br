<?php

namespace App\Domain\Assets\Http\Controllers;

use App\Domain\Assets\Services\AssetAccessService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AssetAuditController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AssetAccessService $access,
    ) {}

    public function index(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'audit');
        $limit = max(1, min(500, (int) $request->query('limit', 200)));
        $items = DB::table('asset_audit_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.app_id', $this->context->id())
            ->where('e.asset_type', $assetType)
            ->where('e.asset_id', $assetId)
            ->orderByDesc('e.occurred_at')
            ->limit($limit)
            ->get(['e.*', 'u.first_name', 'u.last_name', 'u.email as actor_email'])
            ->map(function ($row) {
                $data = (array) $row;
                $data['actor_name'] = trim(($row->first_name ?? '').' '.($row->last_name ?? '')) ?: ($row->actor_email ?? 'Sistema');
                unset($data['first_name'], $data['last_name']);
                $data['before'] = $this->decode($row->before);
                $data['after'] = $this->decode($row->after);
                $data['metadata'] = $this->decode($row->metadata);
                return $data;
            });
        return response()->json(['items' => $items]);
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        if ($value === null || $value === '') return [];
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
