<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class LeasingController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function dashboard(Request $request)
    {
        $userId = (int) $request->user()->id;
        $appId = $this->context->id();
        $leaseIds = DB::table('leases')
            ->where('app_id', $appId)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($request, $userId) {
                $query->where('landlord_user_id', $userId)
                    ->orWhere('tenant_user_id', $userId)
                    ->orWhere('tenant_email', $request->user()->email);
            })
            ->pluck('id');

        $charges = DB::table('lease_charges')->where('app_id', $appId)->whereIn('lease_id', $leaseIds);
        $pending = (clone $charges)->whereIn('status', ['pending', 'processing'])->sum('amount');
        $overdue = (clone $charges)->whereIn('status', ['pending', 'processing'])->whereDate('due_date', '<', today())->sum('amount');
        $received = (clone $charges)->where('status', 'paid')->whereYear('paid_at', now()->year)->sum('amount');

        return response()->json([
            'properties' => DB::table('properties')->where('app_id', $appId)->where('owner_user_id', $userId)->whereNull('deleted_at')->count(),
            'active_leases' => DB::table('leases')->where('app_id', $appId)->whereIn('id', $leaseIds)->where('status', 'active')->whereNull('deleted_at')->count(),
            'awaiting_signature' => DB::table('leases')->where('app_id', $appId)->whereIn('id', $leaseIds)->where('status', 'awaiting_signature')->whereNull('deleted_at')->count(),
            'pending_amount' => round((float) $pending, 2),
            'overdue_amount' => round((float) $overdue, 2),
            'received_this_year' => round((float) $received, 2),
            'next_charges' => DB::table('lease_charges')
                ->where('app_id', $appId)->whereIn('lease_id', $leaseIds)
                ->whereIn('status', ['pending', 'processing'])->orderBy('due_date')->limit(8)->get(),
        ]);
    }

    public function properties(Request $request)
    {
        return response()->json(DB::table('properties')
            ->where('app_id', $this->context->id())
            ->where('owner_user_id', $request->user()->id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')->get()->map(fn ($row) => $this->decodeJsonColumns($row, ['metadata'])));
    }

    public function storeProperty(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'type' => 'required|in:house,apartment,commercial,land,other',
            'use_type' => 'required|in:residential,commercial,mixed',
            'status' => 'nullable|in:available,occupied,maintenance,inactive',
            'postal_code' => 'nullable|string|max:12',
            'street' => 'required|string|max:190',
            'number' => 'nullable|string|max:40',
            'complement' => 'nullable|string|max:120',
            'neighborhood' => 'nullable|string|max:120',
            'city' => 'required|string|max:120',
            'state' => 'required|string|size:2',
            'bedrooms' => 'nullable|integer|min:0|max:100',
            'bathrooms' => 'nullable|integer|min:0|max:100',
            'parking_spaces' => 'nullable|integer|min:0|max:100',
            'area_m2' => 'nullable|numeric|min:0|max:99999999',
            'default_rent_amount' => 'nullable|numeric|min:0',
            'default_due_day' => 'nullable|integer|min:1|max:31',
            'metadata' => 'nullable|array',
        ]);

        $id = DB::table('properties')->insertGetId(array_merge($data, [
            'app_id' => $this->context->id(),
            'owner_user_id' => $request->user()->id,
            'status' => $data['status'] ?? 'available',
            'metadata' => $this->json($data['metadata'] ?? null),
            'created_at' => now(), 'updated_at' => now(),
        ]));

        return response()->json($this->property($request, $id), 201);
    }

    public function updateProperty(Request $request, int $propertyId)
    {
        $this->assertPropertyOwner($request, $propertyId);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:160',
            'type' => 'sometimes|required|in:house,apartment,commercial,land,other',
            'use_type' => 'sometimes|required|in:residential,commercial,mixed',
            'status' => 'sometimes|required|in:available,occupied,maintenance,inactive',
            'postal_code' => 'nullable|string|max:12', 'street' => 'sometimes|required|string|max:190',
            'number' => 'nullable|string|max:40', 'complement' => 'nullable|string|max:120',
            'neighborhood' => 'nullable|string|max:120', 'city' => 'sometimes|required|string|max:120',
            'state' => 'sometimes|required|string|size:2', 'bedrooms' => 'nullable|integer|min:0|max:100',
            'bathrooms' => 'nullable|integer|min:0|max:100', 'parking_spaces' => 'nullable|integer|min:0|max:100',
            'area_m2' => 'nullable|numeric|min:0|max:99999999', 'default_rent_amount' => 'nullable|numeric|min:0',
            'default_due_day' => 'nullable|integer|min:1|max:31', 'metadata' => 'nullable|array',
        ]);
        if (array_key_exists('metadata', $data)) $data['metadata'] = $this->json($data['metadata']);
        $data['updated_at'] = now();
        DB::table('properties')->where('app_id', $this->context->id())->where('id', $propertyId)->update($data);
        return response()->json($this->property($request, $propertyId));
    }

    public function destroyProperty(Request $request, int $propertyId)
    {
        $this->assertPropertyOwner($request, $propertyId);
        abort_if(DB::table('leases')->where('app_id', $this->context->id())->where('property_id', $propertyId)->whereIn('status', ['active', 'awaiting_signature'])->whereNull('deleted_at')->exists(), 422, 'O imóvel possui uma locação ativa ou aguardando assinatura.');
        DB::table('properties')->where('app_id', $this->context->id())->where('id', $propertyId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function leases(Request $request)
    {
        $userId = (int) $request->user()->id;
        $rows = DB::table('leases as l')->join('properties as p', 'p.id', '=', 'l.property_id')
            ->where('l.app_id', $this->context->id())->whereNull('l.deleted_at')
            ->where(function ($query) use ($request, $userId) {
                $query->where('l.landlord_user_id', $userId)->orWhere('l.tenant_user_id', $userId)->orWhere('l.tenant_email', $request->user()->email);
            })
            ->select('l.*', 'p.name as property_name', 'p.city as property_city', 'p.state as property_state')
            ->orderByDesc('l.id')->get();
        return response()->json($rows->map(fn ($row) => $this->decodeLease($row)));
    }

    public function storeLease(Request $request)
    {
        $data = $this->validateLease($request, false);
        $property = $this->assertPropertyOwner($request, (int) $data['property_id']);
        abort_if($property->status === 'occupied', 422, 'Este imóvel já está ocupado por uma locação ativa.');
        $depositMonths = (int) ($data['deposit_months'] ?? 0);
        $rent = (float) $data['rent_amount'];
        $id = DB::table('leases')->insertGetId([
            'public_id' => (string) Str::uuid(), 'app_id' => $this->context->id(), 'property_id' => $property->id,
            'landlord_user_id' => $request->user()->id, 'tenant_user_id' => $data['tenant_user_id'] ?? null,
            'tenant_name' => $data['tenant_name'], 'tenant_email' => $data['tenant_email'] ?? null,
            'tenant_phone' => $data['tenant_phone'] ?? null, 'tenant_tax_id' => $data['tenant_tax_id'] ?? null,
            'purpose' => $data['purpose'], 'status' => 'draft', 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
            'rent_amount' => $rent, 'due_day' => $data['due_day'], 'deposit_months' => $depositMonths,
            'deposit_amount' => $data['deposit_amount'] ?? round($rent * $depositMonths, 2), 'guarantee_type' => $data['guarantee_type'] ?? ($depositMonths > 0 ? 'deposit' : 'none'),
            'adjustment_index' => $data['adjustment_index'] ?? null, 'adjustment_frequency_months' => $data['adjustment_frequency_months'] ?? 12,
            'clauses' => $this->json($data['clauses'] ?? []), 'included_expenses' => $this->json($data['included_expenses'] ?? []),
            'tenant_expenses' => $this->json($data['tenant_expenses'] ?? []), 'metadata' => $this->json($data['metadata'] ?? null),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json($this->leasePayload($request, $id), 201);
    }

    public function showLease(Request $request, int $leaseId)
    {
        $this->assertLeaseAccess($request, $leaseId);
        return response()->json($this->leasePayload($request, $leaseId));
    }

    public function updateLease(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseManager($request, $leaseId);
        abort_if(in_array($lease->status, ['ended', 'cancelled'], true), 422, 'Esta locação já foi encerrada.');
        $data = $this->validateLease($request, true);
        if (isset($data['property_id'])) $this->assertPropertyOwner($request, (int) $data['property_id']);
        foreach (['clauses', 'included_expenses', 'tenant_expenses', 'metadata'] as $column) if (array_key_exists($column, $data)) $data[$column] = $this->json($data[$column]);
        if (isset($data['rent_amount']) || isset($data['deposit_months'])) {
            $rent = (float) ($data['rent_amount'] ?? $lease->rent_amount);
            $months = (int) ($data['deposit_months'] ?? $lease->deposit_months);
            if (! array_key_exists('deposit_amount', $data)) $data['deposit_amount'] = round($rent * $months, 2);
        }
        if (isset($data['status']) && in_array($data['status'], ['ended', 'cancelled'], true)) $data['ended_at'] = now();
        $data['updated_at'] = now();
        DB::transaction(function () use ($lease, $data, $leaseId) {
            DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update($data);
            if (isset($data['status']) && in_array($data['status'], ['ended', 'cancelled'], true)) {
                DB::table('properties')->where('app_id', $this->context->id())->where('id', $lease->property_id)->update(['status' => 'available', 'updated_at' => now()]);
            }
        });
        return response()->json($this->leasePayload($request, $leaseId));
    }

    public function generateContract(Request $request, int $leaseId)
    {
        $this->assertLeaseManager($request, $leaseId);
        $payload = $this->leasePayload($request, $leaseId);
        $lease = $payload['lease'];
        $nextVersion = $lease->contract_generated_at ? ((int) $lease->contract_version + 1) : max(1, (int) $lease->contract_version);
        $lease->contract_version = $nextVersion;
        $contract = $this->buildContract($payload);
        DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update([
            'contract_text' => $contract, 'contract_generated_at' => now(), 'contract_version' => $nextVersion,
            'status' => 'awaiting_signature', 'updated_at' => now(),
        ]);
        DB::table('lease_signatures')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->delete();
        return response()->json($this->leasePayload($request, $leaseId));
    }

    public function sendContract(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseManager($request, $leaseId);
        abort_if(empty($lease->contract_text), 422, 'Gere a minuta do contrato antes do envio.');
        abort_if(empty($lease->tenant_email), 422, 'Informe o e-mail do inquilino.');
        try {
            Mail::raw($lease->contract_text, function ($message) use ($lease) {
                $message->to($lease->tenant_email, $lease->tenant_name)->subject('Contrato de locação para assinatura');
            });
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'O contrato foi gerado, mas o e-mail não pôde ser enviado. Verifique a configuração de e-mail.'], 502);
        }
        return response()->json(['ok' => true, 'sent_to' => $lease->tenant_email]);
    }

    public function sign(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseAccess($request, $leaseId);
        abort_if(empty($lease->contract_text), 422, 'O contrato ainda não foi gerado.');
        $data = $request->validate([
            'party' => 'required|in:landlord,tenant', 'signer_name' => 'required|string|max:190',
            'signer_tax_id' => 'nullable|string|max:32', 'accepted' => 'required|accepted',
        ]);
        $userId = (int) $request->user()->id;
        if ($data['party'] === 'landlord') abort_unless((int) $lease->landlord_user_id === $userId || $this->isAdmin($request), 403);
        if ($data['party'] === 'tenant') {
            $isTenant = (int) $lease->tenant_user_id === $userId || (! $lease->tenant_user_id && $lease->tenant_email && strcasecmp($lease->tenant_email, $request->user()->email) === 0);
            abort_unless($isTenant || $this->isAdmin($request), 403);
            if (! $lease->tenant_user_id && ! $this->isAdmin($request)) DB::table('leases')->where('id', $leaseId)->update(['tenant_user_id' => $userId, 'updated_at' => now()]);
        }
        $hash = hash('sha256', implode('|', [$lease->public_id, $lease->contract_version, $data['party'], $userId, now()->toIso8601String(), $request->ip()]));
        DB::table('lease_signatures')->updateOrInsert(
            ['lease_id' => $leaseId, 'party' => $data['party']],
            ['app_id' => $this->context->id(), 'user_id' => $userId, 'signer_name' => $data['signer_name'], 'signer_email' => $request->user()->email,
             'signer_tax_id' => $data['signer_tax_id'] ?? null, 'signature_type' => 'electronic_acknowledgement', 'signature_hash' => $hash,
             'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000), 'signed_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );
        $parties = DB::table('lease_signatures')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->pluck('party')->all();
        if (in_array('landlord', $parties, true) && in_array('tenant', $parties, true)) {
            DB::transaction(function () use ($lease, $leaseId) {
                DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update(['status' => 'active', 'activated_at' => now(), 'updated_at' => now()]);
                DB::table('properties')->where('app_id', $this->context->id())->where('id', $lease->property_id)->update(['status' => 'occupied', 'updated_at' => now()]);
            });
            $this->ensureRentSchedule($leaseId);
        }
        return response()->json($this->leasePayload($request, $leaseId));
    }

    public function documents(Request $request, int $leaseId)
    {
        $this->assertLeaseAccess($request, $leaseId);
        return response()->json(DB::table('lease_documents')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->orderByDesc('id')->get()->map(fn ($row) => $this->decodeJsonColumns($row, ['metadata'])));
    }

    public function uploadDocument(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseAccess($request, $leaseId);
        $data = $request->validate(['category' => 'required|in:identity,income,address,property,inspection,contract,other', 'file' => 'required|file|max:15360']);
        $file = $data['file'];
        $name = $file->getClientOriginalName();
        $path = $file->storeAs('leasing/'.$this->context->id().'/'.$lease->public_id.'/documents', Str::uuid().'-'.preg_replace('/[^A-Za-z0-9._-]/', '_', $name), 'local');
        $id = DB::table('lease_documents')->insertGetId([
            'app_id' => $this->context->id(), 'lease_id' => $leaseId, 'uploaded_by_user_id' => $request->user()->id,
            'category' => $data['category'], 'name' => $name, 'disk' => 'local', 'path' => $path, 'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(DB::table('lease_documents')->find($id), 201);
    }

    public function downloadDocument(Request $request, int $leaseId, int $documentId)
    {
        $this->assertLeaseAccess($request, $leaseId);
        $document = DB::table('lease_documents')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('id', $documentId)->firstOrFail();
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);
        return Storage::disk($document->disk)->download($document->path, $document->name);
    }

    public function deleteDocument(Request $request, int $leaseId, int $documentId)
    {
        $document = DB::table('lease_documents')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('id', $documentId)->firstOrFail();
        $lease = $this->assertLeaseAccess($request, $leaseId);
        abort_unless((int) $document->uploaded_by_user_id === (int) $request->user()->id || (int) $lease->landlord_user_id === (int) $request->user()->id || $this->isAdmin($request), 403);
        Storage::disk($document->disk)->delete($document->path);
        DB::table('lease_documents')->where('id', $documentId)->delete();
        return response()->json(['ok' => true]);
    }

    public function charges(Request $request, int $leaseId)
    {
        $this->assertLeaseAccess($request, $leaseId);
        return response()->json(DB::table('lease_charges')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->orderBy('due_date')->get()->map(fn ($row) => $this->decodeJsonColumns($row, ['metadata'])));
    }

    public function storeCharge(Request $request, int $leaseId)
    {
        $this->assertLeaseManager($request, $leaseId);
        $data = $request->validate([
            'type' => 'required|in:rent,deposit,iptu,water,electricity,internet,condo,fine,other', 'description' => 'required|string|max:190',
            'reference_date' => 'nullable|date', 'due_date' => 'required|date', 'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|in:pix,boleto,card,cash,transfer,other', 'metadata' => 'nullable|array',
        ]);
        $id = DB::table('lease_charges')->insertGetId([
            'public_id' => (string) Str::uuid(), 'app_id' => $this->context->id(), 'lease_id' => $leaseId,
            'type' => $data['type'], 'description' => $data['description'], 'reference_date' => $data['reference_date'] ?? null,
            'due_date' => $data['due_date'], 'amount' => $data['amount'], 'status' => 'pending', 'payment_method' => $data['payment_method'] ?? null,
            'metadata' => $this->json($data['metadata'] ?? null), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(DB::table('lease_charges')->find($id), 201);
    }

    public function generateRentSchedule(Request $request, int $leaseId)
    {
        $this->assertLeaseManager($request, $leaseId);
        $created = $this->ensureRentSchedule($leaseId);
        return response()->json(['created' => $created, 'charges' => DB::table('lease_charges')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->orderBy('due_date')->get()]);
    }

    public function preparePayment(Request $request, int $leaseId, int $chargeId)
    {
        $lease = $this->assertLeaseAccess($request, $leaseId);
        $data = $request->validate(['method' => 'required|in:pix,boleto,card']);
        $charge = DB::table('lease_charges')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('id', $chargeId)->firstOrFail();
        abort_if($charge->status === 'paid', 422, 'Esta cobrança já está paga.');
        $publicId = (string) Str::uuid();
        DB::table('ecosystem_payments')->updateOrInsert(
            ['app_slug' => $this->context->slug(), 'source_type' => 'lease_charge', 'source_reference' => $charge->public_id],
            ['public_id' => $publicId, 'app_id' => $this->context->id(), 'provider' => 'mercadopago', 'source_id' => $charge->id,
             'user_id' => $lease->tenant_user_id ?: $request->user()->id, 'currency' => 'BRL', 'method' => $data['method'], 'status' => 'pending',
             'gross_amount' => $charge->amount, 'platform_fee' => 0, 'provider_fee' => 0, 'seller_net' => $charge->amount,
             'metadata' => $this->json(['lease_id' => $leaseId, 'charge_id' => $chargeId]), 'updated_at' => now(), 'created_at' => now()]
        );
        $payment = DB::table('ecosystem_payments')->where('app_slug', $this->context->slug())->where('source_type', 'lease_charge')->where('source_reference', $charge->public_id)->first();
        DB::table('lease_charges')->where('id', $chargeId)->update(['ecosystem_payment_id' => $payment->id, 'payment_method' => $data['method'], 'provider' => 'mercadopago', 'status' => 'processing', 'updated_at' => now()]);
        return response()->json(['payment' => $payment, 'charge' => DB::table('lease_charges')->find($chargeId), 'provider_checkout_required' => true]);
    }

    public function markChargePaid(Request $request, int $leaseId, int $chargeId)
    {
        $this->assertLeaseManager($request, $leaseId);
        $data = $request->validate(['payment_method' => 'nullable|in:pix,boleto,card,cash,transfer,other']);
        $charge = DB::table('lease_charges')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('id', $chargeId)->firstOrFail();
        DB::transaction(function () use ($charge, $data, $request) {
            DB::table('lease_charges')->where('id', $charge->id)->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => $data['payment_method'] ?? $charge->payment_method ?? 'other', 'updated_at' => now()]);
            $publicId = (string) Str::uuid();
            DB::table('ecosystem_payments')->updateOrInsert(
                ['app_slug' => $this->context->slug(), 'source_type' => 'lease_charge', 'source_reference' => $charge->public_id],
                ['public_id' => $publicId, 'app_id' => $this->context->id(), 'provider' => $charge->provider ?: 'manual', 'provider_payment_id' => $charge->provider_payment_id,
                 'source_id' => $charge->id, 'user_id' => $request->user()->id, 'currency' => 'BRL', 'method' => $data['payment_method'] ?? $charge->payment_method ?? 'other',
                 'status' => 'paid', 'gross_amount' => $charge->amount, 'platform_fee' => 0, 'provider_fee' => 0, 'seller_net' => $charge->amount,
                 'paid_at' => now(), 'updated_at' => now(), 'created_at' => now()]
            );
            $payment = DB::table('ecosystem_payments')->where('app_slug', $this->context->slug())->where('source_type', 'lease_charge')->where('source_reference', $charge->public_id)->first();
            DB::table('lease_charges')->where('id', $charge->id)->update(['ecosystem_payment_id' => $payment->id]);
        });
        return response()->json(DB::table('lease_charges')->find($chargeId));
    }

    public function inspections(Request $request, int $propertyId)
    {
        $this->assertPropertyOwner($request, $propertyId);
        return response()->json(DB::table('property_inspections')->where('app_id', $this->context->id())->where('property_id', $propertyId)->orderByDesc('occurred_at')->get()->map(fn ($row) => $this->decodeJsonColumns($row, ['items', 'metadata'])));
    }

    public function storeInspection(Request $request, int $propertyId)
    {
        $this->assertPropertyOwner($request, $propertyId);
        $data = $request->validate(['lease_id' => 'nullable|integer', 'type' => 'required|in:entry,periodic,exit', 'occurred_at' => 'required|date', 'summary' => 'nullable|string|max:10000', 'items' => 'nullable|array', 'metadata' => 'nullable|array']);
        if (! empty($data['lease_id'])) abort_unless(DB::table('leases')->where('app_id', $this->context->id())->where('property_id', $propertyId)->where('id', $data['lease_id'])->exists(), 422, 'A locação não pertence a este imóvel.');
        $id = DB::table('property_inspections')->insertGetId(['app_id' => $this->context->id(), 'property_id' => $propertyId, 'lease_id' => $data['lease_id'] ?? null, 'performed_by_user_id' => $request->user()->id, 'type' => $data['type'], 'occurred_at' => $data['occurred_at'], 'summary' => $data['summary'] ?? null, 'items' => $this->json($data['items'] ?? []), 'metadata' => $this->json($data['metadata'] ?? null), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->decodeJsonColumns(DB::table('property_inspections')->find($id), ['items', 'metadata']), 201);
    }

    private function validateLease(Request $request, bool $partial): array
    {
        $required = $partial ? 'sometimes|required' : 'required';
        return $request->validate([
            'property_id' => $required.'|integer', 'tenant_user_id' => 'nullable|integer|exists:users,id',
            'tenant_name' => $required.'|string|max:190', 'tenant_email' => 'nullable|email|max:190', 'tenant_phone' => 'nullable|string|max:40', 'tenant_tax_id' => 'nullable|string|max:32',
            'purpose' => $required.'|in:residential,commercial,mixed', 'status' => 'sometimes|in:draft,awaiting_documents,awaiting_signature,ended,cancelled',
            'starts_on' => $required.'|date', 'ends_on' => $required.'|date|after:starts_on', 'rent_amount' => $required.'|numeric|min:0.01',
            'due_day' => $required.'|integer|min:1|max:31', 'deposit_months' => 'nullable|integer|min:0|max:3', 'deposit_amount' => 'nullable|numeric|min:0',
            'guarantee_type' => 'nullable|in:none,deposit,guarantor,insurance,other', 'adjustment_index' => 'nullable|string|max:40',
            'adjustment_frequency_months' => 'nullable|integer|min:1|max:120', 'clauses' => 'nullable|array', 'included_expenses' => 'nullable|array', 'tenant_expenses' => 'nullable|array', 'metadata' => 'nullable|array',
        ]);
    }

    private function property(Request $request, int $id): object
    {
        return $this->decodeJsonColumns($this->assertPropertyOwner($request, $id), ['metadata']);
    }

    private function assertPropertyOwner(Request $request, int $id): object
    {
        $property = DB::table('properties')->where('app_id', $this->context->id())->where('id', $id)->whereNull('deleted_at')->firstOrFail();
        abort_unless((int) $property->owner_user_id === (int) $request->user()->id || $this->isAdmin($request), 403);
        return $property;
    }

    private function assertLeaseAccess(Request $request, int $id): object
    {
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $id)->whereNull('deleted_at')->firstOrFail();
        $userId = (int) $request->user()->id;
        $allowed = (int) $lease->landlord_user_id === $userId || (int) $lease->tenant_user_id === $userId || ($lease->tenant_email && strcasecmp($lease->tenant_email, $request->user()->email) === 0) || $this->isAdmin($request);
        abort_unless($allowed, 403);
        return $lease;
    }

    private function assertLeaseManager(Request $request, int $id): object
    {
        $lease = $this->assertLeaseAccess($request, $id);
        abort_unless((int) $lease->landlord_user_id === (int) $request->user()->id || $this->isAdmin($request), 403);
        return $lease;
    }

    private function leasePayload(Request $request, int $id): array
    {
        $lease = $this->assertLeaseAccess($request, $id);
        $property = DB::table('properties')->where('app_id', $this->context->id())->where('id', $lease->property_id)->first();
        return [
            'lease' => $this->decodeLease($lease),
            'property' => $this->decodeJsonColumns($property, ['metadata']),
            'signatures' => DB::table('lease_signatures')->where('app_id', $this->context->id())->where('lease_id', $id)->orderBy('id')->get()->map(fn ($row) => $this->decodeJsonColumns($row, ['metadata'])),
            'documents' => DB::table('lease_documents')->where('app_id', $this->context->id())->where('lease_id', $id)->orderByDesc('id')->get()->map(fn ($row) => $this->decodeJsonColumns($row, ['metadata'])),
            'charges' => DB::table('lease_charges')->where('app_id', $this->context->id())->where('lease_id', $id)->orderBy('due_date')->get()->map(fn ($row) => $this->decodeJsonColumns($row, ['metadata'])),
        ];
    }

    private function decodeLease(object $row): object
    {
        return $this->decodeJsonColumns($row, ['clauses', 'included_expenses', 'tenant_expenses', 'metadata']);
    }

    private function decodeJsonColumns(?object $row, array $columns): ?object
    {
        if (! $row) return null;
        foreach ($columns as $column) if (property_exists($row, $column)) $row->{$column} = $row->{$column} ? json_decode($row->{$column}, true) : [];
        return $row;
    }

    private function json(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function ensureRentSchedule(int $leaseId): int
    {
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->firstOrFail();
        $cursor = CarbonImmutable::parse($lease->starts_on)->startOfMonth();
        $end = CarbonImmutable::parse($lease->ends_on)->startOfMonth();
        $created = 0;
        while ($cursor <= $end) {
            $dueDay = min((int) $lease->due_day, $cursor->daysInMonth);
            $due = $cursor->setDay($dueDay);
            $reference = $cursor->toDateString();
            $exists = DB::table('lease_charges')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('type', 'rent')->whereDate('reference_date', $reference)->exists();
            if (! $exists) {
                DB::table('lease_charges')->insert(['public_id' => (string) Str::uuid(), 'app_id' => $this->context->id(), 'lease_id' => $leaseId, 'type' => 'rent', 'description' => 'Aluguel '.$cursor->format('m/Y'), 'reference_date' => $reference, 'due_date' => $due->toDateString(), 'amount' => $lease->rent_amount, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                $created++;
            }
            $cursor = $cursor->addMonth();
        }
        if ((float) $lease->deposit_amount > 0 && ! DB::table('lease_charges')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('type', 'deposit')->exists()) {
            DB::table('lease_charges')->insert(['public_id' => (string) Str::uuid(), 'app_id' => $this->context->id(), 'lease_id' => $leaseId, 'type' => 'deposit', 'description' => 'Caução / garantia locatícia', 'reference_date' => $lease->starts_on, 'due_date' => $lease->starts_on, 'amount' => $lease->deposit_amount, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $created++;
        }
        return $created;
    }

    private function buildContract(array $payload): string
    {
        $l = $payload['lease']; $p = $payload['property'];
        $landlord = DB::table('users')->where('id', $l->landlord_user_id)->first();
        $landlordName = trim(($landlord->first_name ?? '').' '.($landlord->last_name ?? '')) ?: ($landlord->name ?? 'Locador');
        $address = trim(implode(', ', array_filter([$p->street, $p->number, $p->complement, $p->neighborhood, $p->city.'-'.$p->state, $p->postal_code])));
        $included = implode(', ', $l->included_expenses ?: ['nenhuma despesa adicional informada']);
        $tenant = implode(', ', $l->tenant_expenses ?: ['consumos e encargos não expressamente incluídos']);
        $clauses = collect($l->clauses ?: [])->values()->map(fn ($clause, $i) => ($i + 8).'. '.(is_array($clause) ? ($clause['text'] ?? json_encode($clause, JSON_UNESCAPED_UNICODE)) : $clause))->implode("\n\n");
        $deposit = (int) $l->deposit_months > 0
            ? sprintf('A garantia será caução em dinheiro equivalente a %d aluguel(is), no valor de R$ %s, limitada a três meses e a ser depositada em caderneta de poupança, com as vantagens revertidas ao locatário por ocasião do levantamento, conforme a legislação aplicável.', $l->deposit_months, number_format((float) $l->deposit_amount, 2, ',', '.'))
            : 'As partes declaram que não haverá caução em dinheiro, salvo outra garantia expressamente indicada.';
        return trim("CONTRATO DE LOCAÇÃO\n\nLOCADOR: {$landlordName}.\nLOCATÁRIO: {$l->tenant_name}".($l->tenant_tax_id ? ", documento {$l->tenant_tax_id}" : '').($l->tenant_email ? ", e-mail {$l->tenant_email}" : '').".\n\n1. OBJETO. O LOCADOR entrega ao LOCATÁRIO o imóvel denominado {$p->name}, situado em {$address}, para uso {$l->purpose}.\n\n2. PRAZO. A locação vigorará de {$l->starts_on} até {$l->ends_on}.\n\n3. ALUGUEL. O aluguel mensal é de R$ ".number_format((float) $l->rent_amount, 2, ',', '.').", com vencimento no dia {$l->due_day} de cada mês.\n\n4. GARANTIA. {$deposit}\n\n5. REAJUSTE. O aluguel poderá ser reajustado a cada {$l->adjustment_frequency_months} meses".($l->adjustment_index ? " pelo índice {$l->adjustment_index}, observada a legislação aplicável" : ', conforme índice e legislação aplicáveis na data do reajuste').".\n\n6. DESPESAS INCLUÍDAS. {$included}.\n\n7. DESPESAS DO LOCATÁRIO. {$tenant}.".($clauses ? "\n\n{$clauses}" : '')."\n\nO LOCATÁRIO declara ter recebido as condições desta locação e se compromete a preservar o imóvel e cumprir as obrigações pactuadas. As assinaturas eletrônicas registradas pelo sistema identificam o signatário, data, hora, IP e integridade da versão aceita.\n\nDocumento gerado eletronicamente. Versão {$l->contract_version}.");
    }

    private function isAdmin(Request $request): bool
    {
        return method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
    }
}
