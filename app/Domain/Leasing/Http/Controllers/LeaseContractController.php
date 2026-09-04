<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Leasing\Services\LeaseTemplateService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LeaseContractController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseTemplateService $templates,
    ) {}

    public function generate(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        $property = DB::table('properties')
            ->where('app_id', $this->context->id())
            ->where('id', $lease->property_id)
            ->whereNull('deleted_at')
            ->firstOrFail();
        $landlord = DB::table('users')->where('id', $lease->landlord_user_id)->firstOrFail();

        $metadata = $this->decode($lease->metadata);
        $requestedTemplate = data_get($metadata, 'contract.template');
        $template = $this->templates->normalize($requestedTemplate, (string) $lease->purpose);
        $version = $lease->contract_generated_at
            ? ((int) $lease->contract_version + 1)
            : max(1, (int) $lease->contract_version);

        $contract = $this->build($lease, $property, $landlord, $metadata, $version, $template);

        DB::transaction(function () use ($leaseId, $contract, $version, $metadata, $template) {
            $metadata['contract'] = array_merge($metadata['contract'] ?? [], [
                'template' => $template,
                'occupancy_type' => $template === LeaseTemplateService::SHARED_SUITE ? 'shared_unit' : 'whole_property',
                'generated_from_profile' => true,
            ]);

            DB::table('leases')
                ->where('app_id', $this->context->id())
                ->where('id', $leaseId)
                ->update([
                    'contract_text' => $contract,
                    'contract_generated_at' => now(),
                    'contract_version' => $version,
                    'status' => 'awaiting_signature',
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);

            DB::table('lease_signatures')
                ->where('app_id', $this->context->id())
                ->where('lease_id', $leaseId)
                ->delete();
        });

        return app(LeasingController::class)->showLease($request, $leaseId);
    }

    private function managedLease(Request $request, int $leaseId): object
    {
        $lease = DB::table('leases')
            ->where('app_id', $this->context->id())
            ->where('id', $leaseId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $isAdmin = method_exists($request->user(), 'hasProfile')
            && $request->user()->hasProfile('Administrador');

        abort_unless(
            (int) $lease->landlord_user_id === (int) $request->user()->id || $isAdmin,
            403,
        );

        return $lease;
    }

    private function build(
        object $lease,
        object $property,
        object $landlord,
        array $metadata,
        int $version,
        string $template,
    ): string {
        $tenant = data_get($metadata, 'tenant_profile', []);
        $settings = data_get($metadata, 'contract', []);
        $landlordProfile = $this->landlordProfile($landlord);

        $tenantProfile = [
            'name' => $lease->tenant_name,
            'tax_id' => $lease->tenant_tax_id,
            'email' => $lease->tenant_email,
            'phone' => $lease->tenant_phone,
            'birthdate' => data_get($tenant, 'birthdate'),
            'birthplace' => data_get($tenant, 'birthplace'),
            'document_type' => data_get($tenant, 'document_type'),
            'document_number' => data_get($tenant, 'document_number'),
            'document_issuer' => data_get($tenant, 'document_issuer'),
            'parent_1' => data_get($tenant, 'parent_1'),
            'parent_2' => data_get($tenant, 'parent_2'),
            'marital_status' => data_get($tenant, 'marital_status'),
            'occupation' => data_get($tenant, 'occupation'),
            'address' => data_get($tenant, 'address'),
            'city' => data_get($tenant, 'city'),
            'state' => data_get($tenant, 'state'),
            'postal_code' => data_get($tenant, 'postal_code'),
        ];

        $additionalClauses = collect($this->decode($lease->clauses))
            ->map(function ($clause) {
                if (is_array($clause)) return trim((string) ($clause['text'] ?? ''));

                return trim((string) $clause);
            })
            ->filter()
            ->values();

        return $this->templates->renderContract([
            'template' => $template,
            'purpose' => (string) $lease->purpose,
            'lease' => $lease,
            'settings' => $settings,
            'landlord_name' => $landlordProfile['name'],
            'landlord_qualification' => $this->qualification($landlordProfile, 'LOCADOR'),
            'tenant_name' => $lease->tenant_name,
            'tenant_qualification' => $this->qualification($tenantProfile, 'LOCATÁRIO'),
            'property_address' => $this->propertyAddress($property),
            'rent' => $this->money($lease->rent_amount),
            'deposit' => $this->money($lease->deposit_amount),
            'included_expenses' => $this->expenseNames($this->decode($lease->included_expenses)),
            'tenant_expenses' => $this->expenseNames($this->decode($lease->tenant_expenses)),
            'deposit_mode' => data_get($settings, 'deposit_mode', 'last_months_credit'),
            'renewal_notice_month' => (int) data_get($settings, 'renewal_notice_month', 10),
            'iptu_monthly' => (float) data_get($settings, 'iptu_monthly_amount', 0),
            'inspection_required' => (bool) data_get($settings, 'inspection_required', true),
            'security_access' => (bool) data_get($settings, 'security_system_access', false),
            'forum' => data_get(
                $settings,
                'forum',
                trim(($property->city ?? '').'/'.($property->state ?? '')),
            ),
            'starts_on' => $this->date($lease->starts_on),
            'ends_on' => $this->date($lease->ends_on),
            'additional_clauses' => $additionalClauses,
            'version' => $version,
        ]);
    }

    private function landlordProfile(object $user): array
    {
        $extra = $this->decode($user->extra_info ?? null);

        return [
            'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: ($user->name ?? 'Locador'),
            'tax_id' => $user->cpf ?? null,
            'email' => $user->email ?? null,
            'phone' => $user->phone ?? null,
            'birthdate' => $user->birthdate ?? null,
            'birthplace' => data_get($extra, 'birthplace'),
            'document_type' => data_get($extra, 'document_type'),
            'document_number' => data_get($extra, 'document_number'),
            'document_issuer' => data_get($extra, 'document_issuer'),
            'parent_1' => data_get($extra, 'parent_1'),
            'parent_2' => data_get($extra, 'parent_2'),
            'marital_status' => $user->marital_status ?? null,
            'occupation' => $user->occupation ?? null,
            'address' => $user->address ?? null,
            'city' => $user->city ?? null,
            'state' => $user->uf ?? null,
            'postal_code' => $user->postal_code ?? null,
        ];
    }

    private function qualification(array $profile, string $fallback): string
    {
        $name = trim((string) ($profile['name'] ?? '')) ?: $fallback;
        $details = [];

        if (! empty($profile['birthdate'])) $details[] = 'nascido(a) em '.$this->date($profile['birthdate']);
        if (! empty($profile['birthplace'])) $details[] = 'natural de '.$profile['birthplace'];
        if (! empty($profile['marital_status'])) $details[] = $profile['marital_status'];
        if (! empty($profile['occupation'])) $details[] = $profile['occupation'];
        if (! empty($profile['tax_id'])) $details[] = 'inscrito(a) no CPF/CNPJ sob nº '.$profile['tax_id'];
        if (! empty($profile['document_number'])) $details[] = 'portador(a) de '.$this->documentLabel($profile);
        if (! empty($profile['parent_1']) || ! empty($profile['parent_2'])) {
            $details[] = 'filho(a) de '.implode(' e ', array_filter([
                $profile['parent_1'] ?? null,
                $profile['parent_2'] ?? null,
            ]));
        }

        $address = trim(implode(', ', array_filter([
            $profile['address'] ?? null,
            $profile['city'] ?? null,
            $profile['state'] ?? null,
            $profile['postal_code'] ?? null,
        ])));
        if ($address) $details[] = 'residente e domiciliado(a) em '.$address;

        return $name.($details ? ', '.implode(', ', $details) : '');
    }

    private function documentLabel(array $profile): string
    {
        $type = trim((string) ($profile['document_type'] ?? 'documento de identidade'))
            ?: 'documento de identidade';
        $number = (string) ($profile['document_number'] ?? '');
        $issuer = trim((string) ($profile['document_issuer'] ?? ''));

        return trim($type.' nº '.$number.($issuer ? ', expedido por '.$issuer : ''));
    }

    private function propertyAddress(object $property): string
    {
        return trim(implode(', ', array_filter([
            $property->street ?? null,
            $property->number ?? null,
            $property->complement ?? null,
            $property->neighborhood ?? null,
            trim(($property->city ?? '').'/'.($property->state ?? '')),
            $property->postal_code ?? null,
        ])));
    }

    private function expenseNames(array $values): string
    {
        $labels = [
            'iptu' => 'IPTU',
            'water' => 'água',
            'electricity' => 'energia elétrica',
            'internet' => 'internet',
            'condo' => 'condomínio',
        ];

        return collect($values)
            ->map(fn ($value) => $labels[$value] ?? Str::headline((string) $value))
            ->implode(', ');
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function money(mixed $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    private function date(mixed $value): string
    {
        if (! $value) return 'data não informada';

        try {
            return \Carbon\CarbonImmutable::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
