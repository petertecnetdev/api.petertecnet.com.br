<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Leasing\Services\LeaseReadinessService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LeasePackageLifecycleController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseReadinessService $readiness,
    ) {}

    public function generate(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        $checklist = $this->readiness->checklist($lease);
        abort_unless($checklist['ready'], 422, 'Complete o checklist da locação antes de gerar os documentos.');

        $previousMetadata = $this->decode($lease->metadata);
        $previousPackage = data_get($previousMetadata, 'contract_package');
        $historicalSignatures = DB::table('lease_signatures')
            ->where('app_id', $this->context->id())
            ->where('lease_id', $leaseId)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        app(LeaseDocumentPackageController::class)->generate($request, $leaseId);

        if ($historicalSignatures) {
            foreach ($historicalSignatures as $signature) {
                $exists = DB::table('lease_signatures')->where('id', $signature['id'])->exists();
                if (!$exists) DB::table('lease_signatures')->insert($signature);
            }
        }

        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->firstOrFail();
        $property = DB::table('properties')->where('app_id', $this->context->id())->where('id', $lease->property_id)->firstOrFail();
        $landlord = DB::table('users')->where('id', $lease->landlord_user_id)->firstOrFail();
        $metadata = $this->decode($lease->metadata);
        $package = data_get($metadata, 'contract_package', []);

        $documents = collect($package['documents'] ?? [])->map(function ($document) {
            $document['sha256'] = hash('sha256', (string) ($document['content'] ?? ''));
            return $document;
        })->values()->all();

        $snapshot = [
            'captured_at' => now()->toIso8601String(),
            'lease' => [
                'public_id' => $lease->public_id,
                'purpose' => $lease->purpose,
                'starts_on' => $lease->starts_on,
                'ends_on' => $lease->ends_on,
                'rent_amount' => (float) $lease->rent_amount,
                'due_day' => (int) $lease->due_day,
                'deposit_months' => (int) $lease->deposit_months,
                'deposit_amount' => (float) $lease->deposit_amount,
                'guarantee_type' => $lease->guarantee_type,
                'adjustment_index' => $lease->adjustment_index,
                'adjustment_frequency_months' => (int) $lease->adjustment_frequency_months,
                'included_expenses' => $this->decode($lease->included_expenses),
                'tenant_expenses' => $this->decode($lease->tenant_expenses),
                'clauses' => $this->decode($lease->clauses),
            ],
            'tenant' => [
                'user_id' => $lease->tenant_user_id,
                'name' => $lease->tenant_name,
                'email' => $lease->tenant_email,
                'phone' => $lease->tenant_phone,
                'tax_id' => $lease->tenant_tax_id,
                'profile' => data_get($metadata, 'tenant_profile', []),
            ],
            'landlord' => [
                'user_id' => $landlord->id,
                'name' => trim(($landlord->first_name ?? '').' '.($landlord->last_name ?? '')) ?: ($landlord->name ?? null),
                'email' => $landlord->email ?? null,
                'phone' => $landlord->phone ?? null,
                'tax_id' => $landlord->cpf ?? null,
            ],
            'property' => [
                'id' => $property->id,
                'name' => $property->name,
                'type' => $property->type,
                'use_type' => $property->use_type,
                'postal_code' => $property->postal_code,
                'street' => $property->street,
                'number' => $property->number,
                'complement' => $property->complement,
                'neighborhood' => $property->neighborhood,
                'city' => $property->city,
                'state' => $property->state,
            ],
            'contract_settings' => data_get($metadata, 'contract', []),
            'initial_payment' => data_get($metadata, 'workflow.initial_payment', []),
        ];

        if ($previousPackage) {
            $history = collect(data_get($metadata, 'contract_package_versions', []));
            $previousVersion = (int) ($previousPackage['version'] ?? 0);
            if ($previousVersion > 0 && !$history->contains(fn ($item) => (int) ($item['version'] ?? 0) === $previousVersion)) {
                $history->push($previousPackage);
            }
            $metadata['contract_package_versions'] = $history->values()->all();
        }

        $package['documents'] = $documents;
        $package['snapshot'] = $snapshot;
        $package['package_sha256'] = hash('sha256', json_encode([
            'version' => $package['version'] ?? $lease->contract_version,
            'documents' => collect($documents)->pluck('sha256')->all(),
            'snapshot' => $snapshot,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $package['immutable_after_signature'] = true;
        $metadata['contract_package'] = $package;
        $metadata['workflow']['stage'] = 'awaiting_landlord_signature';

        DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update([
            'metadata' => $this->json($metadata),
            'status' => 'awaiting_signature',
            'updated_at' => now(),
        ]);

        return app(LeasingController::class)->showLease($request, $leaseId);
    }

    private function managedLease(Request $request, int $leaseId): object
    {
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->whereNull('deleted_at')->firstOrFail();
        $isAdmin = method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
        abort_unless((int) $lease->landlord_user_id === (int) $request->user()->id || $isAdmin, 403);
        return $lease;
    }

    private function decode($value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array) $value;
        if (!$value) return [];
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function json($value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
