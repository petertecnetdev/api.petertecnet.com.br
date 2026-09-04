<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LeaseDocumentPackageController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function generate(Request $request, int $leaseId)
    {
        app(LeaseContractController::class)->generate($request, $leaseId);

        $lease = DB::table('leases')
            ->where('app_id', $this->context->id())
            ->where('id', $leaseId)
            ->whereNull('deleted_at')
            ->firstOrFail();
        $property = DB::table('properties')->where('app_id', $this->context->id())->where('id', $lease->property_id)->firstOrFail();
        $metadata = $this->decode($lease->metadata);
        $version = (int) $lease->contract_version;

        $documents = [
            [
                'key' => 'lease_contract',
                'title' => 'Contrato de locação',
                'version' => $version,
                'content' => (string) $lease->contract_text,
            ],
            [
                'key' => 'inspection_and_handover',
                'title' => 'Termo de vistoria e entrega do imóvel',
                'version' => $version,
                'content' => $this->inspectionDocument($lease, $property, $metadata, $version),
            ],
            [
                'key' => 'rules_access_and_systems',
                'title' => 'Termo complementar de regras, acessos e sistemas',
                'version' => $version,
                'content' => $this->rulesDocument($lease, $property, $metadata, $version),
            ],
        ];

        $packageText = collect($documents)->map(fn (array $document, int $index) =>
            'DOCUMENTO '.($index + 1).' DE 3 — '.$document['title']."\n\n".$document['content']
        )->implode("\n\n\n============================================================\n\n\n");

        $metadata['contract_package'] = [
            'version' => $version,
            'generated_at' => now()->toIso8601String(),
            'documents' => $documents,
            'signature_scope' => 'package',
            'document_count' => 3,
        ];
        $metadata['workflow'] = array_merge($metadata['workflow'] ?? [], [
            'stage' => 'awaiting_signature',
            'documents_generated_at' => now()->toIso8601String(),
        ]);

        DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update([
            'contract_text' => $packageText,
            'metadata' => $this->json($metadata),
            'status' => 'awaiting_signature',
            'updated_at' => now(),
        ]);

        return app(LeasingController::class)->showLease($request, $leaseId);
    }

    private function inspectionDocument(object $lease, object $property, array $metadata, int $version): string
    {
        $address = $this->propertyAddress($property);
        $inspectionRequired = data_get($metadata, 'contract.inspection_required', true);
        $tenant = $lease->tenant_name ?: 'LOCATÁRIO';
        $landlord = DB::table('users')->where('id', $lease->landlord_user_id)->first();
        $landlordName = trim(($landlord->first_name ?? '').' '.($landlord->last_name ?? '')) ?: ($landlord->name ?? 'LOCADOR');

        $parts = [
            'TERMO DE VISTORIA E ENTREGA DO IMÓVEL',
            "Imóvel: {$address}.",
            "LOCADOR: {$landlordName}.\nLOCATÁRIO: {$tenant}.",
            $inspectionRequired
                ? 'As partes reconhecem que a vistoria inicial, com registros descritivos e/ou fotográficos vinculados à locação, integra este termo e servirá de referência para a devolução do imóvel, ressalvado o desgaste natural decorrente do uso regular.'
                : 'As partes registram que a condição de entrega do imóvel deverá ser documentada na plataforma antes da ocupação, podendo ser complementada por registros descritivos e fotográficos.',
            'O LOCATÁRIO deverá comunicar divergências relevantes observadas após a entrada no imóvel dentro do prazo acordado entre as partes. Reparos decorrentes de mau uso, alterações não autorizadas ou danos atribuíveis ao ocupante serão apurados na saída.',
            'Na devolução, serão conferidos conservação, limpeza, chaves, instalações, equipamentos, contas e demais obrigações registradas na locação.',
            "Data prevista de início da locação: {$this->date($lease->starts_on)}.\nVersão documental: {$version}.",
            'Este termo integra o pacote contratual eletrônico e será aceito conjuntamente com os demais documentos da mesma versão.',
        ];

        return implode("\n\n", $parts);
    }

    private function rulesDocument(object $lease, object $property, array $metadata, int $version): string
    {
        $settings = data_get($metadata, 'contract', []);
        $included = $this->expenseNames($this->decode($lease->included_expenses));
        $tenantExpenses = $this->expenseNames($this->decode($lease->tenant_expenses));
        $security = (bool) data_get($settings, 'security_system_access', false);
        $iptu = (float) data_get($settings, 'iptu_monthly_amount', 0);
        $depositMode = data_get($settings, 'deposit_mode', 'last_months_credit');

        $parts = [
            'TERMO COMPLEMENTAR DE REGRAS, ACESSOS E SISTEMAS',
            'Este termo complementa o contrato principal e registra condições operacionais variáveis definidas para esta locação.',
            'Despesas sob responsabilidade do LOCATÁRIO: '.($tenantExpenses ?: 'as não expressamente incluídas pelo LOCADOR').'.',
            'Despesas incluídas no aluguel: '.($included ?: 'nenhuma despesa adicional registrada como incluída').'.',
        ];

        if ($iptu > 0) $parts[] = 'IPTU mensal registrado separadamente: '.$this->money($iptu).'.';
        if ((int) $lease->deposit_months > 0) {
            $parts[] = 'Caução registrada: '.$this->money($lease->deposit_amount).', correspondente a '.((int) $lease->deposit_months).' aluguel(is). Tratamento acordado: '.($depositMode === 'last_months_credit' ? 'abatimento nos últimos aluguéis, quando cumpridas as condições contratuais' : 'acerto financeiro ao encerramento da locação').'.';
        }
        $parts[] = $security
            ? 'Há previsão de acesso para instalação, manutenção ou substituição de sistemas de segurança, mediante comunicação e em condições razoáveis, respeitando a privacidade e a finalidade da locação.'
            : 'Não há condição especial de acesso para sistemas de segurança registrada nesta versão, sem prejuízo de futuras condições formalizadas por escrito.';

        $custom = collect($this->decode($lease->clauses))->map(function ($clause) {
            if (is_array($clause)) return trim((string) ($clause['text'] ?? ''));
            return trim((string) $clause);
        })->filter()->values();
        if ($custom->isNotEmpty()) {
            $parts[] = "Condições adicionais acordadas:\n- ".$custom->implode("\n- ");
        }

        $parts[] = 'Imóvel: '.$this->propertyAddress($property).'.';
        $parts[] = "Versão documental: {$version}. Este termo integra o mesmo aceite eletrônico do contrato e do termo de vistoria desta versão.";

        return implode("\n\n", $parts);
    }

    private function propertyAddress(object $property): string
    {
        return collect([
            trim(($property->street ?? '').' '.($property->number ?? '')),
            $property->complement ?? null,
            $property->neighborhood ?? null,
            trim(($property->city ?? '').'/'.($property->state ?? '')),
            $property->postal_code ? 'CEP '.$property->postal_code : null,
        ])->filter()->implode(', ');
    }

    private function expenseNames(array $items): string
    {
        $labels = [
            'iptu' => 'IPTU', 'water' => 'água', 'electricity' => 'energia elétrica',
            'internet' => 'internet', 'condo' => 'condomínio',
        ];
        return collect($items)->map(fn ($item) => $labels[$item] ?? (string) $item)->filter()->implode(', ');
    }

    private function money($value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    private function date($value): string
    {
        if (!$value) return 'não informada';
        try { return \Carbon\CarbonImmutable::parse($value)->format('d/m/Y'); } catch (\Throwable) { return (string) $value; }
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
