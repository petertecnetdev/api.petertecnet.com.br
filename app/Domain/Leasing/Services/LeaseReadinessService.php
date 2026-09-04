<?php

namespace App\Domain\Leasing\Services;

use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;

final class LeaseReadinessService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function checklist(object $lease): array
    {
        $metadata = $this->decode($lease->metadata ?? null);
        $tenant = data_get($metadata, 'tenant_profile', []);
        $requiredDocumentCategories = collect(data_get($metadata, 'workflow.required_document_categories', ['identity', 'address']))->filter()->values();
        $documents = DB::table('lease_documents')
            ->where('app_id', $this->context->id())
            ->where('lease_id', $lease->id)
            ->get();
        $presentCategories = $documents->pluck('category')->unique();

        $items = [
            $this->item('tenant_email', 'E-mail do inquilino', filled($lease->tenant_email)),
            $this->item('tenant_name', 'Nome do inquilino', filled($lease->tenant_name)),
            $this->item('tenant_tax_id', 'CPF/CNPJ do inquilino', filled($lease->tenant_tax_id)),
            $this->item('tenant_birthdate', 'Data de nascimento', filled(data_get($tenant, 'birthdate'))),
            $this->item('tenant_document', 'Documento de identidade', filled(data_get($tenant, 'document_number'))),
            $this->item('tenant_address', 'Endereço atual do inquilino', filled(data_get($tenant, 'address')) && filled(data_get($tenant, 'city')) && filled(data_get($tenant, 'state'))),
            $this->item('lease_dates', 'Período da locação', filled($lease->starts_on) && filled($lease->ends_on)),
            $this->item('rent', 'Valor do aluguel', (float) $lease->rent_amount > 0),
            $this->item('due_day', 'Dia de vencimento', (int) $lease->due_day >= 1 && (int) $lease->due_day <= 31),
        ];

        foreach ($requiredDocumentCategories as $category) {
            $items[] = $this->item('document_'.$category, $this->documentLabel($category), $presentCategories->contains($category));
        }

        $missing = collect($items)->where('complete', false)->values();

        return [
            'ready' => $missing->isEmpty(),
            'items' => $items,
            'missing' => $missing,
            'required_document_categories' => $requiredDocumentCategories,
            'documents_received' => $documents->count(),
        ];
    }

    public function signaturesComplete(object $lease): bool
    {
        $parties = DB::table('lease_signatures')
            ->where('app_id', $this->context->id())
            ->where('lease_id', $lease->id)
            ->where('contract_version', $lease->contract_version)
            ->pluck('party');

        return $parties->contains('landlord') && $parties->contains('tenant');
    }

    public function initialPaymentsComplete(object $lease): bool
    {
        $metadata = $this->decode($lease->metadata ?? null);
        $agreement = data_get($metadata, 'workflow.initial_payment', []);
        $expected = [];
        if (($agreement['collect_deposit'] ?? ((float) $lease->deposit_amount > 0)) && (float) $lease->deposit_amount > 0) $expected[] = 'deposit';
        if (($agreement['collect_first_rent'] ?? true) && (float) $lease->rent_amount > 0) $expected[] = 'rent';

        $additional = collect($agreement['additional_charges'] ?? [])->filter(fn ($charge) => (float) ($charge['amount'] ?? 0) > 0);
        $expectedCount = count($expected) + $additional->count();
        if ($expectedCount === 0) return true;

        $paid = DB::table('lease_charges')
            ->where('app_id', $this->context->id())
            ->where('lease_id', $lease->id)
            ->where('status', 'paid')
            ->where(function ($query) {
                $query->whereIn('type', ['deposit', 'rent', 'other'])
                    ->orWhere('metadata', 'like', '%lease_onboarding%');
            })
            ->count();

        return $paid >= $expectedCount;
    }

    private function item(string $key, string $label, bool $complete): array
    {
        return compact('key', 'label', 'complete');
    }

    private function documentLabel(string $category): string
    {
        return match ($category) {
            'identity' => 'Documento de identidade enviado',
            'address' => 'Comprovante de endereço enviado',
            'income' => 'Comprovante de renda enviado',
            default => 'Documento '.str_replace('_', ' ', $category).' enviado',
        };
    }

    private function decode($value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array) $value;
        if (!$value) return [];
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
