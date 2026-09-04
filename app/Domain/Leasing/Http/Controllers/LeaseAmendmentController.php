<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Leasing\Services\LeaseRevisionService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LeaseAmendmentController extends Controller
{
    private const TYPES = ['rent_adjustment', 'term_extension', 'expense_change', 'guarantee_change', 'clause_change', 'other'];
    private const MUTABLE_FIELDS = ['rent_amount', 'due_day', 'ends_on', 'adjustment_index', 'adjustment_frequency_months', 'clauses', 'included_expenses', 'tenant_expenses', 'guarantee_type', 'deposit_amount'];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseRevisionService $revisions,
    ) {}

    public function index(Request $request, int $leaseId)
    {
        $this->assertLeaseAccess($request, $leaseId);
        $rows = DB::table('lease_amendments')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->orderByDesc('id')->get();
        return response()->json($rows->map(fn ($row) => $this->payload($row)));
    }

    public function revisions(Request $request, int $leaseId)
    {
        $this->assertLeaseAccess($request, $leaseId);
        $rows = DB::table('lease_revisions')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->orderByDesc('sequence')->get();
        return response()->json($rows->map(function ($row) {
            $row->integrity_valid = $this->revisions->verify($row);
            $row->snapshot = $this->decode($row->snapshot);
            $row->changes = $this->decode($row->changes);
            return $row;
        }));
    }

    public function store(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseManager($request, $leaseId);
        abort_if(in_array($lease->status, ['cancelled'], true), 422, 'Não é possível criar aditivo para uma locação cancelada.');

        $data = $request->validate([
            'type' => 'required|string|in:'.implode(',', self::TYPES),
            'effective_on' => 'required|date',
            'summary' => 'required|string|max:255',
            'changes' => 'required|array|min:1|max:20',
        ]);
        $changes = array_intersect_key($data['changes'], array_flip(self::MUTABLE_FIELDS));
        abort_if($changes === [] || count($changes) !== count($data['changes']), 422, 'O aditivo contém alterações não permitidas.');
        $changes = $this->normalizeChanges($changes);
        $this->validateResultingLease($lease, $changes, $leaseId);

        $before = $this->revisions->snapshot($this->context->id(), $leaseId);
        if (! DB::table('lease_revisions')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->exists()) {
            $this->revisions->record($this->context->id(), $leaseId, (int) $request->user()->id, 'baseline', null, $before);
        }

        $document = $this->buildDocument($lease, $data['summary'], $data['effective_on'], $changes);
        $id = DB::table('lease_amendments')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'app_id' => $this->context->id(),
            'lease_id' => $leaseId,
            'created_by_user_id' => $request->user()->id,
            'type' => $data['type'],
            'status' => 'awaiting_signature',
            'effective_on' => $data['effective_on'],
            'summary' => $data['summary'],
            'changes' => $this->json($changes),
            'document_text' => $document,
            'document_sha256' => hash('sha256', $document),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json($this->payload(DB::table('lease_amendments')->where('id', $id)->first()), 201);
    }

    public function sign(Request $request, int $leaseId, int $amendmentId)
    {
        $lease = $this->assertLeaseAccess($request, $leaseId);
        $amendment = $this->amendment($leaseId, $amendmentId);
        abort_unless($amendment->status === 'awaiting_signature', 422, 'Este aditivo não está disponível para assinatura.');
        $data = $request->validate(['party' => 'required|in:landlord,tenant', 'signer_name' => 'required|string|max:190', 'accepted' => 'required|accepted']);
        $userId = (int) $request->user()->id;
        $isAdmin = $this->isAdmin($request);
        if ($data['party'] === 'landlord') abort_unless((int) $lease->landlord_user_id === $userId || $isAdmin, 403);
        if ($data['party'] === 'tenant') {
            $isTenant = (int) $lease->tenant_user_id === $userId || ($lease->tenant_email && strcasecmp($lease->tenant_email, (string) $request->user()->email) === 0);
            abort_unless($isTenant || $isAdmin, 403);
        }

        $signedAt = now();
        $signatureHash = hash('sha256', implode('|', [$amendment->document_sha256, $data['party'], $userId, $signedAt->toIso8601String(), $request->ip()]));
        DB::table('lease_amendment_signatures')->updateOrInsert(
            ['amendment_id' => $amendmentId, 'party' => $data['party']],
            ['app_id' => $this->context->id(), 'user_id' => $userId, 'signer_name' => $data['signer_name'], 'signer_email' => $request->user()->email, 'signature_hash' => $signatureHash, 'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''), 'signed_at' => $signedAt, 'created_at' => $signedAt, 'updated_at' => $signedAt]
        );

        $parties = DB::table('lease_amendment_signatures')->where('amendment_id', $amendmentId)->pluck('party')->unique()->all();
        if (in_array('landlord', $parties, true) && in_array('tenant', $parties, true)) $this->activate($request, $lease, $amendment);

        return response()->json($this->payload(DB::table('lease_amendments')->where('id', $amendmentId)->first()));
    }

    public function cancel(Request $request, int $leaseId, int $amendmentId)
    {
        $this->assertLeaseManager($request, $leaseId);
        $amendment = $this->amendment($leaseId, $amendmentId);
        abort_if($amendment->status === 'active', 422, 'Um aditivo já efetivado não pode ser cancelado retroativamente.');
        DB::table('lease_amendments')->where('id', $amendmentId)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
        return response()->json($this->payload(DB::table('lease_amendments')->where('id', $amendmentId)->first()));
    }

    private function activate(Request $request, object $lease, object $amendment): void
    {
        $changes = $this->decode($amendment->changes);
        $this->validateResultingLease($lease, $changes, (int) $lease->id);
        $before = $this->revisions->snapshot($this->context->id(), (int) $lease->id);
        $update = $changes;
        foreach (['clauses', 'included_expenses', 'tenant_expenses'] as $key) if (array_key_exists($key, $update)) $update[$key] = $this->json($update[$key]);
        $update['updated_at'] = now();

        DB::transaction(function () use ($lease, $amendment, $update) {
            DB::table('leases')->where('app_id', $this->context->id())->where('id', $lease->id)->update($update);
            DB::table('lease_amendments')->where('id', $amendment->id)->update(['status' => 'active', 'activated_at' => now(), 'updated_at' => now()]);
            if (isset($update['rent_amount'])) {
                DB::table('lease_charges')->where('app_id', $this->context->id())->where('lease_id', $lease->id)->where('type', 'rent')->where('status', 'pending')->whereDate('due_date', '>=', $amendment->effective_on)->update(['amount' => $update['rent_amount'], 'updated_at' => now()]);
            }
        });

        $this->revisions->record($this->context->id(), (int) $lease->id, (int) $request->user()->id, 'amendment_activated', $before);
    }

    private function validateResultingLease(object $lease, array $changes, int $leaseId): void
    {
        $rent = (float) ($changes['rent_amount'] ?? $lease->rent_amount);
        abort_if($rent <= 0, 422, 'O aluguel deve ser maior que zero.');
        $dueDay = (int) ($changes['due_day'] ?? $lease->due_day);
        abort_if($dueDay < 1 || $dueDay > 31, 422, 'Dia de vencimento inválido.');
        $endsOn = (string) ($changes['ends_on'] ?? $lease->ends_on);
        abort_if(strtotime($endsOn) <= strtotime((string) $lease->starts_on), 422, 'A data final deve ser posterior ao início.');

        if (array_key_exists('ends_on', $changes)) {
            $overlap = DB::table('leases')->where('app_id', $this->context->id())->where('property_id', $lease->property_id)->where('id', '!=', $leaseId)->whereNull('deleted_at')->whereNotIn('status', ['ended', 'cancelled'])
                ->whereDate('starts_on', '<=', $endsOn)->whereDate('ends_on', '>=', $lease->starts_on)->exists();
            abort_if($overlap, 422, 'A nova vigência conflita com outra locação deste imóvel.');
        }
    }

    private function normalizeChanges(array $changes): array
    {
        if (isset($changes['rent_amount'])) $changes['rent_amount'] = round((float) $changes['rent_amount'], 2);
        if (isset($changes['deposit_amount'])) $changes['deposit_amount'] = round((float) $changes['deposit_amount'], 2);
        if (isset($changes['due_day'])) $changes['due_day'] = (int) $changes['due_day'];
        if (isset($changes['adjustment_frequency_months'])) $changes['adjustment_frequency_months'] = (int) $changes['adjustment_frequency_months'];
        foreach (['clauses', 'included_expenses', 'tenant_expenses'] as $field) if (isset($changes[$field]) && ! is_array($changes[$field])) abort(422, $field.' deve ser uma lista.');
        return $changes;
    }

    private function buildDocument(object $lease, string $summary, string $effectiveOn, array $changes): string
    {
        $lines = ['ADITIVO CONTRATUAL', '', 'Contrato: '.$lease->public_id, 'Vigência do aditivo: '.$effectiveOn, 'Resumo: '.$summary, '', 'Alterações acordadas:'];
        foreach ($changes as $field => $value) $lines[] = '- '.$field.': '.(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value);
        $lines[] = '';
        $lines[] = 'Este aditivo integra o contrato original e somente produz efeitos após a assinatura eletrônica das partes.';
        return implode("\n", $lines);
    }

    private function payload(object $row): array
    {
        $signatures = DB::table('lease_amendment_signatures')->where('amendment_id', $row->id)->orderBy('party')->get()->map(fn ($s) => (array) $s)->all();
        $data = (array) $row;
        $data['changes'] = $this->decode($row->changes);
        $data['signatures'] = $signatures;
        return $data;
    }

    private function amendment(int $leaseId, int $amendmentId): object
    {
        return DB::table('lease_amendments')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('id', $amendmentId)->firstOrFail();
    }

    private function assertLeaseAccess(Request $request, int $leaseId): object
    {
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->whereNull('deleted_at')->firstOrFail();
        $userId = (int) $request->user()->id;
        $tenantEmail = $lease->tenant_email && strcasecmp($lease->tenant_email, (string) $request->user()->email) === 0;
        abort_unless((int) $lease->landlord_user_id === $userId || (int) $lease->tenant_user_id === $userId || $tenantEmail || $this->isAdmin($request) || $this->hasGrant($userId, $lease), 403);
        return $lease;
    }

    private function assertLeaseManager(Request $request, int $leaseId): object
    {
        $lease = $this->assertLeaseAccess($request, $leaseId);
        $userId = (int) $request->user()->id;
        abort_unless((int) $lease->landlord_user_id === $userId || $this->isAdmin($request) || $this->hasGrant($userId, $lease, ['manage', 'contracts']), 403);
        return $lease;
    }

    private function hasGrant(int $userId, object $lease, array $permissions = []): bool
    {
        $rows = DB::table('leasing_access_grants')->where('app_id', $this->context->id())->where('user_id', $userId)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->where('lease_id', $lease->id)->orWhere('property_id', $lease->property_id))->get();
        if ($permissions === []) return $rows->isNotEmpty();
        return $rows->contains(function ($row) use ($permissions) {
            if (in_array($row->role, ['owner', 'manager', 'proxy'], true)) return true;
            $granted = $this->decode($row->permissions);
            return count(array_intersect($permissions, $granted)) > 0;
        });
    }

    private function isAdmin(Request $request): bool
    {
        return method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
    }

    private function decode($value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array) $value;
        if (! $value) return [];
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function json($value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
