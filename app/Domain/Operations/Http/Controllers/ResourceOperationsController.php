<?php

namespace App\Domain\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ResourceOperationsController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function operations(Request $request)
    {
        $query = DB::table('resource_operations')
            ->where('app_id', $this->context->id())
            ->where('user_id', $request->user()->id)
            ->whereNull('deleted_at');

        foreach (['resource_type', 'resource_id', 'operation_type', 'status', 'priority'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->input($field));
        }

        return response()->json($query->orderByRaw('due_at is null')->orderBy('due_at')->orderByDesc('id')->limit(200)->get()->map(fn ($row) => $this->decode($row)));
    }

    public function storeOperation(Request $request)
    {
        $data = $request->validate([
            'resource_type' => 'required|string|max:80',
            'resource_id' => 'required|integer|min:1',
            'operation_type' => 'required|string|in:task,maintenance,inspection,document,follow_up',
            'title' => 'required|string|max:190',
            'description' => 'nullable|string|max:5000',
            'status' => 'nullable|string|in:open,in_progress,blocked,completed,cancelled',
            'priority' => 'nullable|string|in:low,normal,high,critical',
            'due_at' => 'nullable|date',
            'estimated_amount' => 'nullable|numeric|min:0',
            'actual_amount' => 'nullable|numeric|min:0',
            'metadata' => 'nullable|array',
        ]);

        $id = DB::table('resource_operations')->insertGetId([
            ...$data,
            'app_id' => $this->context->id(),
            'user_id' => $request->user()->id,
            'status' => $data['status'] ?? 'open',
            'priority' => $data['priority'] ?? 'normal',
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json($this->operation($request, $id), 201);
    }

    public function updateOperation(Request $request, int $operationId)
    {
        $this->operation($request, $operationId);
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:190',
            'description' => 'nullable|string|max:5000',
            'status' => 'sometimes|required|string|in:open,in_progress,blocked,completed,cancelled',
            'priority' => 'sometimes|required|string|in:low,normal,high,critical',
            'due_at' => 'nullable|date',
            'estimated_amount' => 'nullable|numeric|min:0',
            'actual_amount' => 'nullable|numeric|min:0',
            'metadata' => 'nullable|array',
        ]);
        if (array_key_exists('metadata', $data)) $data['metadata'] = json_encode($data['metadata']);
        if (($data['status'] ?? null) === 'completed') $data['completed_at'] = now();
        $data['updated_at'] = now();

        DB::table('resource_operations')->where('app_id', $this->context->id())->where('user_id', $request->user()->id)->where('id', $operationId)->update($data);
        return response()->json($this->operation($request, $operationId));
    }

    public function financialEntries(Request $request)
    {
        $query = DB::table('resource_financial_entries')
            ->where('app_id', $this->context->id())
            ->where('user_id', $request->user()->id)
            ->whereNull('deleted_at');

        foreach (['resource_type', 'resource_id', 'direction', 'category', 'status'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->input($field));
        }

        return response()->json($query->orderByDesc('due_on')->orderByDesc('id')->limit(300)->get()->map(fn ($row) => $this->decode($row)));
    }

    public function storeFinancialEntry(Request $request)
    {
        $data = $request->validate([
            'resource_type' => 'required|string|max:80',
            'resource_id' => 'required|integer|min:1',
            'direction' => 'required|string|in:income,expense',
            'category' => 'nullable|string|max:80',
            'description' => 'required|string|max:190',
            'amount' => 'required|numeric|min:0.01',
            'due_on' => 'nullable|date',
            'paid_at' => 'nullable|date',
            'status' => 'nullable|string|in:pending,paid,cancelled,overdue',
            'recurrence' => 'nullable|string|in:none,monthly,quarterly,semiannual,annual',
            'metadata' => 'nullable|array',
        ]);

        $id = DB::table('resource_financial_entries')->insertGetId([
            ...$data,
            'app_id' => $this->context->id(),
            'user_id' => $request->user()->id,
            'status' => $data['status'] ?? (($data['paid_at'] ?? null) ? 'paid' : 'pending'),
            'recurrence' => $data['recurrence'] ?? 'none',
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json($this->financialEntry($request, $id), 201);
    }

    public function updateFinancialEntry(Request $request, int $entryId)
    {
        $this->financialEntry($request, $entryId);
        $data = $request->validate([
            'category' => 'nullable|string|max:80',
            'description' => 'sometimes|required|string|max:190',
            'amount' => 'sometimes|required|numeric|min:0.01',
            'due_on' => 'nullable|date',
            'paid_at' => 'nullable|date',
            'status' => 'sometimes|required|string|in:pending,paid,cancelled,overdue',
            'recurrence' => 'nullable|string|in:none,monthly,quarterly,semiannual,annual',
            'metadata' => 'nullable|array',
        ]);
        if (array_key_exists('metadata', $data)) $data['metadata'] = json_encode($data['metadata']);
        $data['updated_at'] = now();

        DB::table('resource_financial_entries')->where('app_id', $this->context->id())->where('user_id', $request->user()->id)->where('id', $entryId)->update($data);
        return response()->json($this->financialEntry($request, $entryId));
    }

    public function timeline(Request $request, string $resourceType, int $resourceId)
    {
        $operations = DB::table('resource_operations')->where('app_id', $this->context->id())->where('user_id', $request->user()->id)
            ->where('resource_type', $resourceType)->where('resource_id', $resourceId)->whereNull('deleted_at')->get()
            ->map(fn ($row) => ['kind' => 'operation', 'at' => $row->completed_at ?: $row->due_at ?: $row->created_at, 'data' => $this->decode($row)]);
        $financial = DB::table('resource_financial_entries')->where('app_id', $this->context->id())->where('user_id', $request->user()->id)
            ->where('resource_type', $resourceType)->where('resource_id', $resourceId)->whereNull('deleted_at')->get()
            ->map(fn ($row) => ['kind' => 'financial_entry', 'at' => $row->paid_at ?: $row->due_on ?: $row->created_at, 'data' => $this->decode($row)]);

        return response()->json($operations->concat($financial)->sortByDesc('at')->values());
    }

    public function preferences(Request $request)
    {
        return response()->json(DB::table('portfolio_preferences')->where('app_id', $this->context->id())->where('user_id', $request->user()->id)->get()->mapWithKeys(function ($row) {
            return [$row->key => json_decode($row->value, true)];
        }));
    }

    public function savePreference(Request $request, string $key)
    {
        $data = $request->validate(['value' => 'required']);
        DB::table('portfolio_preferences')->updateOrInsert(
            ['app_id' => $this->context->id(), 'user_id' => $request->user()->id, 'key' => $key],
            ['value' => json_encode($data['value']), 'updated_at' => now(), 'created_at' => now()]
        );
        return response()->json(['ok' => true, 'key' => $key, 'value' => $data['value']]);
    }

    public function search(Request $request)
    {
        $term = trim((string) $request->query('q'));
        abort_if(mb_strlen($term) < 2, 422, 'Informe ao menos 2 caracteres.');
        $userId = (int) $request->user()->id;
        $appId = $this->context->id();
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        $properties = DB::table('properties')->where('app_id', $appId)->where('owner_user_id', $userId)->whereNull('deleted_at')
            ->where(function ($q) use ($like) { $q->where('name', 'like', $like)->orWhere('street', 'like', $like)->orWhere('city', 'like', $like); })
            ->limit(8)->get(['id', 'name', 'street', 'city'])->map(fn ($r) => ['type' => 'property', 'id' => $r->id, 'title' => $r->name, 'subtitle' => trim(($r->street ?: '').' · '.($r->city ?: ''), ' ·')]);

        $leases = DB::table('leases')->where('app_id', $appId)->where('landlord_user_id', $userId)->whereNull('deleted_at')
            ->where(function ($q) use ($like) { $q->where('tenant_name', 'like', $like)->orWhere('tenant_email', 'like', $like)->orWhere('tenant_tax_id', 'like', $like); })
            ->limit(8)->get(['id', 'tenant_name', 'tenant_email'])->map(fn ($r) => ['type' => 'lease', 'id' => $r->id, 'title' => $r->tenant_name ?: 'Locação #'.$r->id, 'subtitle' => $r->tenant_email]);

        $operations = DB::table('resource_operations')->where('app_id', $appId)->where('user_id', $userId)->whereNull('deleted_at')
            ->where(function ($q) use ($like) { $q->where('title', 'like', $like)->orWhere('description', 'like', $like); })
            ->limit(8)->get(['id', 'resource_type', 'resource_id', 'title', 'operation_type'])->map(fn ($r) => ['type' => 'operation', 'id' => $r->id, 'resource_type' => $r->resource_type, 'resource_id' => $r->resource_id, 'title' => $r->title, 'subtitle' => $r->operation_type]);

        return response()->json($properties->concat($leases)->concat($operations)->values());
    }

    private function operation(Request $request, int $id): object
    {
        $row = DB::table('resource_operations')->where('app_id', $this->context->id())->where('user_id', $request->user()->id)->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        return $this->decode($row);
    }

    private function financialEntry(Request $request, int $id): object
    {
        $row = DB::table('resource_financial_entries')->where('app_id', $this->context->id())->where('user_id', $request->user()->id)->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        return $this->decode($row);
    }

    private function decode(object $row): object
    {
        if (property_exists($row, 'metadata') && $row->metadata) $row->metadata = json_decode($row->metadata, true);
        return $row;
    }
}
