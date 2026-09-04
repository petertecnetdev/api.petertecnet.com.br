<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LeaseOperationsController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function actionCenter(Request $request)
    {
        $leaseIds = $this->leaseIdsForUser($request);
        if ($leaseIds->isEmpty()) return response()->json(['items' => [], 'counts' => ['critical' => 0, 'attention' => 0, 'upcoming' => 0]]);

        $items = collect();
        $appId = $this->context->id();
        $userId = (int) $request->user()->id;
        $today = CarbonImmutable::today();

        DB::table('lease_charges as c')->join('leases as l', 'l.id', '=', 'c.lease_id')->join('properties as p', 'p.id', '=', 'l.property_id')
            ->where('c.app_id', $appId)->whereIn('c.lease_id', $leaseIds)->whereIn('c.status', ['pending', 'processing'])
            ->whereDate('c.due_date', '<', $today->toDateString())->orderBy('c.due_date')->limit(50)
            ->get(['c.*', 'l.tenant_name', 'l.landlord_user_id', 'p.name as property_name'])->each(function ($row) use ($items) {
                $items->push($this->task('charge_overdue', 'critical', 'Cobrança vencida', $row->description.' · '.$row->property_name, $row->due_date, $row->lease_id, $row->property_name, ['charge_id' => $row->id, 'amount' => (float) $row->amount]));
            });

        DB::table('lease_charges as c')->join('leases as l', 'l.id', '=', 'c.lease_id')->join('properties as p', 'p.id', '=', 'l.property_id')
            ->where('c.app_id', $appId)->whereIn('c.lease_id', $leaseIds)->whereIn('c.status', ['pending', 'processing'])
            ->whereBetween('c.due_date', [$today->toDateString(), $today->addDays(7)->toDateString()])->orderBy('c.due_date')->limit(50)
            ->get(['c.*', 'p.name as property_name'])->each(function ($row) use ($items) {
                $items->push($this->task('charge_due', 'upcoming', 'Cobrança próxima', $row->description.' · '.$row->property_name, $row->due_date, $row->lease_id, $row->property_name, ['charge_id' => $row->id, 'amount' => (float) $row->amount]));
            });

        $leases = DB::table('leases as l')->join('properties as p', 'p.id', '=', 'l.property_id')
            ->where('l.app_id', $appId)->whereIn('l.id', $leaseIds)->whereNull('l.deleted_at')
            ->get(['l.*', 'p.name as property_name']);

        foreach ($leases as $lease) {
            $isManager = (int) $lease->landlord_user_id === $userId || $this->isAdmin($request);
            if ($lease->status === 'awaiting_signature') {
                $signed = DB::table('lease_signatures')->where('app_id', $appId)->where('lease_id', $lease->id)->pluck('party')->all();
                $missing = array_values(array_diff(['landlord', 'tenant'], $signed));
                if ($missing) $items->push($this->task('signature_missing', 'attention', 'Assinatura pendente', $lease->property_name.' · falta '.implode(' e ', $missing), null, $lease->id, $lease->property_name, ['missing_parties' => $missing]));
            }

            if ($isManager && in_array($lease->status, ['draft', 'awaiting_documents'], true)) {
                $required = $this->documentRequirementRows($lease->id);
                $uploaded = DB::table('lease_documents')->where('app_id', $appId)->where('lease_id', $lease->id)->pluck('category')->all();
                $missing = collect($required)->pluck('category')->diff($uploaded)->values()->all();
                if ($missing) $items->push($this->task('documents_missing', 'attention', 'Documentos pendentes', $lease->property_name.' · '.count($missing).' documento(s) faltando', null, $lease->id, $lease->property_name, ['categories' => $missing]));
            }

            if ($isManager && $lease->status === 'active' && $lease->ends_on) {
                $ends = CarbonImmutable::parse($lease->ends_on);
                $days = $today->diffInDays($ends, false);
                if ($days >= 0 && $days <= 60) $items->push($this->task('lease_expiring', $days <= 30 ? 'attention' : 'upcoming', 'Contrato perto do fim', $lease->property_name.' · termina em '.$days.' dia(s)', $lease->ends_on, $lease->id, $lease->property_name));
            }

            if ($isManager && $lease->status === 'active' && $lease->starts_on) {
                $frequency = max(1, (int) ($lease->adjustment_frequency_months ?: 12));
                $last = DB::table('lease_operations')->where('app_id', $appId)->where('lease_id', $lease->id)->where('type', 'rent_adjustment')->whereNull('deleted_at')->orderByDesc('occurred_at')->first();
                $base = $last?->occurred_at ? CarbonImmutable::parse($last->occurred_at) : CarbonImmutable::parse($lease->starts_on);
                $next = $base->addMonths($frequency);
                $days = $today->diffInDays($next, false);
                if ($days <= 30) $items->push($this->task('rent_adjustment', $days < 0 ? 'attention' : 'upcoming', $days < 0 ? 'Reajuste vencido' : 'Reajuste próximo', $lease->property_name.' · índice '.($lease->adjustment_index ?: 'a definir'), $next->toDateString(), $lease->id, $lease->property_name));
            }
        }

        DB::table('lease_operations as o')->leftJoin('properties as p', 'p.id', '=', 'o.property_id')
            ->where('o.app_id', $appId)->whereIn('o.lease_id', $leaseIds)->whereNull('o.deleted_at')->whereIn('o.status', ['open', 'in_progress', 'waiting'])
            ->whereIn('o.type', ['maintenance', 'termination', 'inspection_followup'])->orderByRaw("FIELD(o.priority, 'urgent', 'high', 'normal', 'low')")->orderBy('o.due_at')
            ->limit(50)->get(['o.*', 'p.name as property_name'])->each(function ($row) use ($items) {
                $priority = in_array($row->priority, ['urgent', 'high'], true) ? 'critical' : 'attention';
                $items->push($this->task($row->type, $priority, $row->title, $row->description ?: ($row->property_name ?: 'Locação'), $row->due_at, $row->lease_id, $row->property_name, ['operation_id' => $row->id, 'status' => $row->status]));
            });

        $rank = ['critical' => 0, 'attention' => 1, 'upcoming' => 2];
        $sorted = $items->unique(fn ($item) => implode(':', [$item['type'], $item['lease_id'] ?? 0, $item['data']['charge_id'] ?? $item['data']['operation_id'] ?? $item['due_at'] ?? '']))
            ->sortBy(fn ($item) => sprintf('%d-%s', $rank[$item['priority']] ?? 9, $item['due_at'] ?: '9999-12-31'))->values();

        return response()->json([
            'items' => $sorted,
            'counts' => [
                'critical' => $sorted->where('priority', 'critical')->count(),
                'attention' => $sorted->where('priority', 'attention')->count(),
                'upcoming' => $sorted->where('priority', 'upcoming')->count(),
            ],
        ]);
    }

    public function timeline(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseAccess($request, $leaseId);
        $appId = $this->context->id();
        $events = collect([
            $this->event('lease_created', 'Locação criada', 'O fluxo da locação foi iniciado.', $lease->created_at, ['status' => $lease->status]),
        ]);
        if ($lease->contract_generated_at) $events->push($this->event('contract_generated', 'Contrato gerado', 'Minuta versão '.$lease->contract_version.' gerada.', $lease->contract_generated_at));
        if ($lease->activated_at) $events->push($this->event('lease_activated', 'Locação ativada', 'As assinaturas foram concluídas e a locação entrou em vigência.', $lease->activated_at));
        if ($lease->ended_at) $events->push($this->event('lease_ended', 'Locação encerrada', 'A vigência foi encerrada.', $lease->ended_at));

        DB::table('lease_documents')->where('app_id', $appId)->where('lease_id', $leaseId)->get()->each(fn ($row) => $events->push($this->event('document', 'Documento recebido', $row->name, $row->created_at, ['document_id' => $row->id, 'category' => $row->category, 'status' => $row->status])));
        DB::table('lease_signatures')->where('app_id', $appId)->where('lease_id', $leaseId)->get()->each(fn ($row) => $events->push($this->event('signature', 'Contrato assinado', ($row->party === 'tenant' ? 'Inquilino' : 'Locador').' · '.$row->signer_name, $row->signed_at, ['party' => $row->party])));
        DB::table('lease_charges')->where('app_id', $appId)->where('lease_id', $leaseId)->get()->each(function ($row) use ($events) {
            $events->push($this->event('charge_created', 'Cobrança programada', $row->description, $row->created_at, ['charge_id' => $row->id, 'amount' => (float) $row->amount, 'due_date' => $row->due_date]));
            if ($row->paid_at) $events->push($this->event('charge_paid', 'Pagamento recebido', $row->description, $row->paid_at, ['charge_id' => $row->id, 'amount' => (float) $row->amount]));
        });
        DB::table('property_inspections')->where('app_id', $appId)->where('lease_id', $leaseId)->get()->each(fn ($row) => $events->push($this->event('inspection', 'Vistoria '.($row->type === 'entry' ? 'de entrada' : ($row->type === 'exit' ? 'de saída' : 'periódica')), $row->summary ?: 'Vistoria registrada.', $row->occurred_at, ['inspection_id' => $row->id, 'type' => $row->type])));
        DB::table('lease_operations')->where('app_id', $appId)->where('lease_id', $leaseId)->whereNull('deleted_at')->get()->each(function ($row) use ($events) {
            $payload = $this->jsonDecode($row->payload);
            $events->push($this->event('operation_'.$row->type, $row->title, $row->description ?: ucfirst(str_replace('_', ' ', $row->type)), $row->occurred_at ?: $row->created_at, ['operation_id' => $row->id, 'status' => $row->status, 'type' => $row->type, 'amount' => $row->amount ? (float) $row->amount : null, 'payload' => $payload]));
        });

        return response()->json($events->filter(fn ($event) => ! empty($event['at']))->sortByDesc('at')->values());
    }

    public function documentRequirements(Request $request, int $leaseId)
    {
        $this->assertLeaseAccess($request, $leaseId);
        $uploaded = DB::table('lease_documents')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->get()->groupBy('category');
        $rows = collect($this->documentRequirementRows($leaseId))->map(function ($requirement) use ($uploaded) {
            $documents = $uploaded->get($requirement['category'], collect());
            return array_merge($requirement, ['fulfilled' => $documents->isNotEmpty(), 'documents' => $documents->map(fn ($doc) => ['id' => $doc->id, 'name' => $doc->name, 'status' => $doc->status])->values()]);
        });
        return response()->json(['requirements' => $rows, 'complete' => $rows->every('fulfilled')]);
    }

    public function setDocumentRequirements(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseManager($request, $leaseId);
        $data = $request->validate(['requirements' => 'required|array|min:1|max:20', 'requirements.*.category' => 'required|in:identity,income,address,property,inspection,contract,other', 'requirements.*.label' => 'required|string|max:120', 'requirements.*.party' => 'nullable|in:landlord,tenant,both', 'requirements.*.required' => 'nullable|boolean']);
        DB::transaction(function () use ($request, $lease, $leaseId, $data) {
            DB::table('lease_operations')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('type', 'document_requirement')->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
            foreach ($data['requirements'] as $requirement) {
                $this->insertOperation($request, $lease, 'document_requirement', 'open', $requirement['label'], null, null, null, ['category' => $requirement['category'], 'party' => $requirement['party'] ?? 'tenant', 'required' => $requirement['required'] ?? true]);
            }
        });
        return $this->documentRequirements($request, $leaseId);
    }

    public function previewAdjustment(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseManager($request, $leaseId);
        $data = $request->validate(['percentage' => 'required|numeric|between:-50,100', 'effective_on' => 'required|date', 'index' => 'nullable|string|max:40']);
        $old = round((float) $lease->rent_amount, 2);
        $new = round($old * (1 + ((float) $data['percentage'] / 100)), 2);
        abort_if($new <= 0, 422, 'O valor reajustado precisa ser maior que zero.');
        $affected = DB::table('lease_charges')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('type', 'rent')->where('status', 'pending')->whereDate('due_date', '>=', $data['effective_on'])->count();
        return response()->json(['old_rent' => $old, 'new_rent' => $new, 'difference' => round($new - $old, 2), 'percentage' => (float) $data['percentage'], 'index' => $data['index'] ?? $lease->adjustment_index, 'effective_on' => $data['effective_on'], 'future_charges_affected' => $affected]);
    }

    public function applyAdjustment(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseManager($request, $leaseId);
        $data = $request->validate(['percentage' => 'required|numeric|between:-50,100', 'effective_on' => 'required|date', 'index' => 'nullable|string|max:40', 'notes' => 'nullable|string|max:5000']);
        $old = round((float) $lease->rent_amount, 2);
        $new = round($old * (1 + ((float) $data['percentage'] / 100)), 2);
        abort_if($new <= 0, 422, 'O valor reajustado precisa ser maior que zero.');
        DB::transaction(function () use ($request, $lease, $leaseId, $data, $old, $new) {
            DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update(['rent_amount' => $new, 'adjustment_index' => $data['index'] ?? $lease->adjustment_index, 'updated_at' => now()]);
            DB::table('lease_charges')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('type', 'rent')->where('status', 'pending')->whereDate('due_date', '>=', $data['effective_on'])->update(['amount' => $new, 'updated_at' => now()]);
            $this->insertOperation($request, $lease, 'rent_adjustment', 'completed', 'Aluguel reajustado', $data['notes'] ?? null, $data['effective_on'], $new, ['old_rent' => $old, 'new_rent' => $new, 'percentage' => (float) $data['percentage'], 'index' => $data['index'] ?? $lease->adjustment_index, 'effective_on' => $data['effective_on']], $data['effective_on']);
        });
        return response()->json(['ok' => true, 'old_rent' => $old, 'new_rent' => $new]);
    }

    public function maintenance(Request $request, int $leaseId)
    {
        $this->assertLeaseAccess($request, $leaseId);
        return response()->json(DB::table('lease_operations')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('type', 'maintenance')->whereNull('deleted_at')->orderByDesc('id')->get()->map(fn ($row) => $this->decodeOperation($row)));
    }

    public function storeMaintenance(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseAccess($request, $leaseId);
        $data = $request->validate(['title' => 'required|string|max:190', 'description' => 'nullable|string|max:10000', 'priority' => 'nullable|in:low,normal,high,urgent', 'responsibility' => 'nullable|in:landlord,tenant,shared,undecided', 'due_at' => 'nullable|date', 'estimated_cost' => 'nullable|numeric|min:0', 'metadata' => 'nullable|array']);
        $id = $this->insertOperation($request, $lease, 'maintenance', 'open', $data['title'], $data['description'] ?? null, $data['due_at'] ?? null, $data['estimated_cost'] ?? null, ['responsibility' => $data['responsibility'] ?? 'undecided', 'metadata' => $data['metadata'] ?? []], null, $data['priority'] ?? 'normal');
        return response()->json($this->decodeOperation(DB::table('lease_operations')->find($id)), 201);
    }

    public function updateMaintenance(Request $request, int $leaseId, int $operationId)
    {
        $this->assertLeaseManager($request, $leaseId);
        $operation = DB::table('lease_operations')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('type', 'maintenance')->where('id', $operationId)->whereNull('deleted_at')->firstOrFail();
        $data = $request->validate(['status' => 'sometimes|in:open,in_progress,waiting,completed,cancelled', 'priority' => 'sometimes|in:low,normal,high,urgent', 'title' => 'sometimes|string|max:190', 'description' => 'nullable|string|max:10000', 'due_at' => 'nullable|date', 'amount' => 'nullable|numeric|min:0', 'responsibility' => 'nullable|in:landlord,tenant,shared,undecided', 'notes' => 'nullable|string|max:5000']);
        $payload = $this->jsonDecode($operation->payload);
        foreach (['responsibility', 'notes'] as $key) if (array_key_exists($key, $data)) { $payload[$key] = $data[$key]; unset($data[$key]); }
        $data['payload'] = $this->json($payload); $data['updated_at'] = now();
        if (($data['status'] ?? null) === 'completed') $data['completed_at'] = now();
        DB::table('lease_operations')->where('id', $operationId)->update($data);
        return response()->json($this->decodeOperation(DB::table('lease_operations')->find($operationId)));
    }

    public function termination(Request $request, int $leaseId)
    {
        $this->assertLeaseAccess($request, $leaseId);
        $row = DB::table('lease_operations')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('type', 'termination')->whereNull('deleted_at')->orderByDesc('id')->first();
        return response()->json($row ? $this->decodeOperation($row) : null);
    }

    public function startTermination(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseManager($request, $leaseId);
        abort_if(in_array($lease->status, ['ended', 'cancelled'], true), 422, 'A locação já está encerrada.');
        $data = $request->validate(['expected_end_on' => 'required|date', 'reason' => 'nullable|string|max:5000']);
        $existing = DB::table('lease_operations')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('type', 'termination')->whereIn('status', ['open', 'in_progress'])->whereNull('deleted_at')->first();
        if ($existing) return response()->json($this->decodeOperation($existing));
        $checklist = ['notice' => false, 'final_charges' => false, 'utilities' => false, 'exit_inspection' => false, 'keys' => false, 'deposit' => false, 'repairs' => false, 'closing_term' => false];
        $id = $this->insertOperation($request, $lease, 'termination', 'open', 'Encerramento da locação', $data['reason'] ?? null, $data['expected_end_on'], null, ['expected_end_on' => $data['expected_end_on'], 'checklist' => $checklist]);
        return response()->json($this->decodeOperation(DB::table('lease_operations')->find($id)), 201);
    }

    public function completeTermination(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseManager($request, $leaseId);
        $operation = DB::table('lease_operations')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('type', 'termination')->whereIn('status', ['open', 'in_progress'])->whereNull('deleted_at')->orderByDesc('id')->firstOrFail();
        $data = $request->validate(['checklist' => 'required|array', 'ended_on' => 'required|date', 'notes' => 'nullable|string|max:10000', 'confirm_end' => 'required|accepted']);
        $payload = $this->jsonDecode($operation->payload); $payload['checklist'] = $data['checklist']; $payload['ended_on'] = $data['ended_on']; $payload['notes'] = $data['notes'] ?? null;
        DB::transaction(function () use ($lease, $operation, $payload, $data) {
            DB::table('lease_operations')->where('id', $operation->id)->update(['status' => 'completed', 'payload' => $this->json($payload), 'occurred_at' => $data['ended_on'], 'completed_at' => now(), 'updated_at' => now()]);
            DB::table('leases')->where('app_id', $this->context->id())->where('id', $lease->id)->update(['status' => 'ended', 'ended_at' => now(), 'ends_on' => $data['ended_on'], 'updated_at' => now()]);
            DB::table('properties')->where('app_id', $this->context->id())->where('id', $lease->property_id)->update(['status' => 'available', 'updated_at' => now()]);
        });
        return response()->json(['ok' => true, 'termination' => $this->decodeOperation(DB::table('lease_operations')->find($operation->id))]);
    }

    public function portfolio(Request $request)
    {
        $appId = $this->context->id(); $userId = (int) $request->user()->id; $year = (int) $request->integer('year', now()->year);
        $properties = DB::table('properties')->where('app_id', $appId)->where('owner_user_id', $userId)->whereNull('deleted_at')->orderBy('name')->get();
        $rows = $properties->map(function ($property) use ($appId, $year) {
            $leaseIds = DB::table('leases')->where('app_id', $appId)->where('property_id', $property->id)->whereNull('deleted_at')->pluck('id');
            $charges = DB::table('lease_charges')->where('app_id', $appId)->whereIn('lease_id', $leaseIds)->whereYear('due_date', $year);
            $expected = (float) (clone $charges)->sum('amount');
            $received = (float) (clone $charges)->where('status', 'paid')->sum('amount');
            $overdue = (float) (clone $charges)->whereIn('status', ['pending', 'processing'])->whereDate('due_date', '<', today())->sum('amount');
            $maintenance = (float) DB::table('lease_operations')->where('app_id', $appId)->where('property_id', $property->id)->where('type', 'maintenance')->whereNull('deleted_at')->whereYear('created_at', $year)->sum('amount');
            $activeLease = DB::table('leases')->where('app_id', $appId)->where('property_id', $property->id)->where('status', 'active')->whereNull('deleted_at')->first();
            return ['property_id' => $property->id, 'property_name' => $property->name, 'status' => $property->status, 'expected' => round($expected, 2), 'received' => round($received, 2), 'overdue' => round($overdue, 2), 'maintenance' => round($maintenance, 2), 'net_cash' => round($received - $maintenance, 2), 'collection_rate' => $expected > 0 ? round(($received / $expected) * 100, 1) : null, 'occupied' => (bool) $activeLease, 'active_lease_id' => $activeLease?->id];
        });
        return response()->json(['year' => $year, 'properties' => $rows, 'summary' => ['expected' => round((float) $rows->sum('expected'), 2), 'received' => round((float) $rows->sum('received'), 2), 'overdue' => round((float) $rows->sum('overdue'), 2), 'maintenance' => round((float) $rows->sum('maintenance'), 2), 'net_cash' => round((float) $rows->sum('net_cash'), 2), 'occupied' => $rows->where('occupied', true)->count(), 'total' => $rows->count()]]);
    }

    public function tenantPortal(Request $request)
    {
        $appId = $this->context->id(); $userId = (int) $request->user()->id; $email = (string) $request->user()->email;
        $leases = DB::table('leases as l')->join('properties as p', 'p.id', '=', 'l.property_id')
            ->where('l.app_id', $appId)->whereNull('l.deleted_at')->where(function ($query) use ($userId, $email) { $query->where('l.tenant_user_id', $userId)->orWhere(function ($q) use ($email) { $q->whereNull('l.tenant_user_id')->where('l.tenant_email', $email); }); })
            ->select('l.*', 'p.name as property_name', 'p.street', 'p.number', 'p.city', 'p.state')->orderByDesc('l.id')->get();
        $items = $leases->map(function ($lease) use ($appId) {
            $charges = DB::table('lease_charges')->where('app_id', $appId)->where('lease_id', $lease->id)->orderBy('due_date')->get();
            $documents = DB::table('lease_documents')->where('app_id', $appId)->where('lease_id', $lease->id)->orderByDesc('id')->get(['id', 'category', 'name', 'status', 'created_at']);
            $maintenance = DB::table('lease_operations')->where('app_id', $appId)->where('lease_id', $lease->id)->where('type', 'maintenance')->whereNull('deleted_at')->orderByDesc('id')->get()->map(fn ($row) => $this->decodeOperation($row));
            return ['lease' => $lease, 'next_charges' => $charges->whereIn('status', ['pending', 'processing'])->take(12)->values(), 'recent_payments' => $charges->where('status', 'paid')->sortByDesc('paid_at')->take(12)->values(), 'documents' => $documents, 'maintenance' => $maintenance];
        });
        return response()->json(['leases' => $items]);
    }

    private function documentRequirementRows(int $leaseId): array
    {
        $custom = DB::table('lease_operations')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('type', 'document_requirement')->whereNull('deleted_at')->orderBy('id')->get();
        if ($custom->isNotEmpty()) return $custom->map(function ($row) { $payload = $this->jsonDecode($row->payload); return ['category' => $payload['category'] ?? 'other', 'label' => $row->title, 'party' => $payload['party'] ?? 'tenant', 'required' => (bool) ($payload['required'] ?? true)]; })->all();
        return [
            ['category' => 'identity', 'label' => 'Documento de identificação', 'party' => 'tenant', 'required' => true],
            ['category' => 'address', 'label' => 'Comprovante de endereço', 'party' => 'tenant', 'required' => true],
            ['category' => 'income', 'label' => 'Comprovante de renda', 'party' => 'tenant', 'required' => true],
        ];
    }

    private function leaseIdsForUser(Request $request)
    {
        $userId = (int) $request->user()->id;
        if ($this->isAdmin($request)) return DB::table('leases')->where('app_id', $this->context->id())->whereNull('deleted_at')->pluck('id');
        return DB::table('leases')->where('app_id', $this->context->id())->whereNull('deleted_at')->where(function ($query) use ($request, $userId) { $query->where('landlord_user_id', $userId)->orWhere('tenant_user_id', $userId)->orWhere('tenant_email', $request->user()->email); })->pluck('id');
    }

    private function assertLeaseAccess(Request $request, int $id): object
    {
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $id)->whereNull('deleted_at')->firstOrFail();
        $userId = (int) $request->user()->id;
        $allowed = (int) $lease->landlord_user_id === $userId || (int) $lease->tenant_user_id === $userId || ($lease->tenant_email && strcasecmp($lease->tenant_email, (string) $request->user()->email) === 0) || $this->isAdmin($request);
        abort_unless($allowed, 403);
        return $lease;
    }

    private function assertLeaseManager(Request $request, int $id): object
    {
        $lease = $this->assertLeaseAccess($request, $id);
        abort_unless((int) $lease->landlord_user_id === (int) $request->user()->id || $this->isAdmin($request), 403);
        return $lease;
    }

    private function insertOperation(Request $request, object $lease, string $type, string $status, string $title, ?string $description = null, mixed $dueAt = null, mixed $amount = null, array $payload = [], mixed $occurredAt = null, string $priority = 'normal'): int
    {
        return DB::table('lease_operations')->insertGetId(['public_id' => (string) Str::uuid(), 'app_id' => $this->context->id(), 'lease_id' => $lease->id, 'property_id' => $lease->property_id, 'actor_user_id' => $request->user()->id, 'type' => $type, 'status' => $status, 'priority' => $priority, 'title' => $title, 'description' => $description, 'due_at' => $dueAt, 'occurred_at' => $occurredAt ?: now(), 'completed_at' => $status === 'completed' ? now() : null, 'amount' => $amount, 'payload' => $this->json($payload), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function decodeOperation(object $row): object
    {
        $row->payload = $this->jsonDecode($row->payload);
        $row->amount = $row->amount === null ? null : (float) $row->amount;
        return $row;
    }

    private function task(string $type, string $priority, string $title, string $description, mixed $dueAt, ?int $leaseId, ?string $propertyName, array $data = []): array
    {
        return ['type' => $type, 'priority' => $priority, 'title' => $title, 'description' => $description, 'due_at' => $dueAt, 'lease_id' => $leaseId, 'property_name' => $propertyName, 'data' => $data];
    }

    private function event(string $type, string $title, string $description, mixed $at, array $data = []): array
    {
        return ['type' => $type, 'title' => $title, 'description' => $description, 'at' => $at, 'data' => $data];
    }

    private function json(mixed $value): ?string { return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    private function jsonDecode(mixed $value): array { if (is_array($value)) return $value; return $value ? (json_decode((string) $value, true) ?: []) : []; }
    private function isAdmin(Request $request): bool { return method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador'); }
}
