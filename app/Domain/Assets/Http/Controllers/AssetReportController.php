<?php

namespace App\Domain\Assets\Http\Controllers;

use App\Domain\Assets\Services\AssetAccessService;
use App\Domain\Assets\Services\AssetIntelligenceService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssetReportController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AssetAccessService $access,
        private readonly AssetIntelligenceService $intelligence,
    ) {}

    public function dossier(Request $request, string $assetType, int $assetId)
    {
        $asset = $this->access->assertAccess($request, $assetType, $assetId, 'report');
        $permissions = $this->access->permissions($request, $assetType, $assetId);
        $canFinancial = in_array('*', $permissions, true) || in_array('financial', $permissions, true);
        $canAudit = in_array('*', $permissions, true) || in_array('audit', $permissions, true);

        $profile = $this->intelligence->profile($assetType, $assetId);
        $analytics = $canFinancial ? $this->intelligence->analytics($assetType, $assetId) : ['health' => $this->intelligence->health($assetType, $assetId)];
        $financial = $canFinancial ? $this->intelligence->financial($assetType, $assetId) : null;
        $alerts = $this->intelligence->alerts($assetType, $assetId);
        $tags = DB::table('asset_tags')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->orderBy('tag')->pluck('tag')->all();
        $ownerships = DB::table('asset_ownerships as o')->join('users as u', 'u.id', '=', 'o.user_id')
            ->where('o.app_id', $this->context->id())->where('o.asset_type', $assetType)->where('o.asset_id', $assetId)
            ->orderByDesc('o.is_primary')->get(['o.role', 'o.share_percent', 'o.is_primary', 'u.first_name', 'u.last_name', 'u.email']);
        $spaces = DB::table('asset_spaces')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->whereNull('deleted_at')->orderBy('sort_order')->get();
        $inventory = DB::table('asset_inventory_items as i')->leftJoin('asset_spaces as s', 's.id', '=', 'i.space_id')
            ->where('i.app_id', $this->context->id())->where('i.asset_type', $assetType)->where('i.asset_id', $assetId)->whereNull('i.deleted_at')->orderBy('s.sort_order')->orderBy('i.name')->get(['i.*', 's.name as space_name']);
        $preventive = DB::table('asset_maintenance_plans as p')->leftJoin('asset_spaces as s', 's.id', '=', 'p.space_id')
            ->where('p.app_id', $this->context->id())->where('p.asset_type', $assetType)->where('p.asset_id', $assetId)->whereNull('p.deleted_at')->orderBy('p.next_due_on')->get(['p.*', 's.name as space_name']);
        $files = DB::table('files')->where('app_id', $this->context->id())->where('entity_name', $assetType)->where('entity_id', $assetId)->where('status', 'active')->orderByDesc('is_primary')->orderByDesc('id')->get()->map(function ($file) { $data = (array) $file; $data['meta'] = $this->decode($file->meta); return $data; });

        $leases = collect(); $inspections = collect(); $maintenance = collect();
        if ($assetType === 'property') {
            $leases = DB::table('leases')->where('app_id', $this->context->id())->where('property_id', $assetId)->whereNull('deleted_at')->orderByDesc('starts_on')->get();
            $inspections = DB::table('property_inspections')->where('app_id', $this->context->id())->where('property_id', $assetId)->orderByDesc('occurred_at')->get()->map(function ($row) { $data = (array) $row; $data['items'] = $this->decode($row->items); return $data; });
            $maintenance = DB::table('lease_operations')->where('app_id', $this->context->id())->where('property_id', $assetId)->where('type', 'maintenance')->whereNull('deleted_at')->orderByDesc('id')->get()->map(function ($row) { $data = (array) $row; $data['payload'] = $this->decode($row->payload); return $data; });
        }

        $audit = collect();
        if ($canAudit) {
            $audit = DB::table('asset_audit_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
                ->where('e.app_id', $this->context->id())->where('e.asset_type', $assetType)->where('e.asset_id', $assetId)
                ->orderByDesc('e.occurred_at')->limit(100)->get(['e.*', 'u.first_name', 'u.last_name', 'u.email as actor_email'])
                ->map(function ($row) { $data = (array) $row; $data['actor_name'] = trim(($row->first_name ?? '').' '.($row->last_name ?? '')) ?: ($row->actor_email ?? 'Sistema'); return $data; });
        }

        $pdf = Pdf::loadView('reports.asset-dossier', [
            'assetType' => $assetType, 'asset' => $asset, 'profile' => $profile, 'analytics' => $analytics, 'financial' => $financial,
            'alerts' => $alerts, 'tags' => $tags, 'ownerships' => $ownerships, 'spaces' => $spaces, 'inventory' => $inventory,
            'preventive' => $preventive, 'files' => $files, 'leases' => $leases, 'inspections' => $inspections, 'maintenance' => $maintenance,
            'audit' => $audit, 'generatedAt' => now(), 'generatedBy' => $request->user(),
        ])->setPaper('a4', 'portrait');

        $name = $assetType === 'property' ? ($asset->name ?? 'patrimonio') : $assetType.'-'.$assetId;
        $filename = 'dossie-'.Str::slug((string) $name).'-'.now()->format('Y-m-d').'.pdf';
        return $pdf->download($filename);
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        if ($value === null || $value === '') return [];
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
