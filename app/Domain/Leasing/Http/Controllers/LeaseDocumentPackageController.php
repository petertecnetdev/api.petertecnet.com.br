<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Leasing\Services\LeaseTemplateService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LeaseDocumentPackageController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseTemplateService $templates,
    ) {}

    public function generate(Request $request, int $leaseId)
    {
        app(LeaseContractController::class)->generate($request, $leaseId);

        $lease = DB::table('leases')
            ->where('app_id', $this->context->id())
            ->where('id', $leaseId)
            ->whereNull('deleted_at')
            ->firstOrFail();
        $property = DB::table('properties')
            ->where('app_id', $this->context->id())
            ->where('id', $lease->property_id)
            ->firstOrFail();

        $metadata = $this->decode($lease->metadata);
        $version = (int) $lease->contract_version;
        $template = $this->templates->normalize(
            data_get($metadata, 'contract.template'),
            (string) $lease->purpose,
        );

        $documents = $this->documentsFor($lease, $property, $metadata, $version, $template);
        $count = count($documents);
        $packageText = collect($documents)->map(
            fn (array $document, int $index) =>
                'DOCUMENTO '.($index + 1)." DE {$count} — ".$document['title']."\n\n".$document['content']
        )->implode("\n\n\n============================================================\n\n\n");

        $metadata['contract_package'] = [
            'version' => $version,
            'template' => $template,
            'template_label' => $this->templates->label($template),
            'generated_at' => now()->toIso8601String(),
            'documents' => $documents,
            'signature_scope' => 'package',
            'document_count' => $count,
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

    private function documentsFor(object $lease, object $property, array $metadata, int $version, string $template): array
    {
        $documents = [[
            'key' => 'lease_contract',
            'title' => $this->contractTitle($template),
            'version' => $version,
            'content' => (string) $lease->contract_text,
        ]];

        if ($template === LeaseTemplateService::SHARED_SUITE) {
            $documents[] = [
                'key' => 'shared_house_rules',
                'title' => 'Termo de ciência das regras da casa compartilhada',
                'version' => $version,
                'content' => $this->sharedHouseRulesDocument($lease, $property, $metadata, $version),
            ];
            $documents[] = [
                'key' => 'inspection_and_handover',
                'title' => 'Termo de vistoria, ocupação e entrega de chaves',
                'version' => $version,
                'content' => $this->inspectionDocument($lease, $property, $metadata, $version),
            ];

            return $documents;
        }

        if ((int) $lease->deposit_months > 0) {
            $documents[] = [
                'key' => 'deposit_acknowledgement',
                'title' => 'Termo de ciência sobre a caução',
                'version' => $version,
                'content' => $this->depositAcknowledgement($lease, $property, $metadata, $version),
            ];
        } else {
            $documents[] = [
                'key' => 'inspection_and_handover',
                'title' => 'Termo de vistoria e entrega do imóvel',
                'version' => $version,
                'content' => $this->inspectionDocument($lease, $property, $metadata, $version),
            ];
        }

        if ((bool) data_get($metadata, 'contract.security_system_access', false)) {
            $documents[] = [
                'key' => 'security_addendum',
                'title' => 'Aditivo de instalação e manutenção de sistemas de segurança',
                'version' => $version,
                'content' => $this->securityAddendum($lease, $property, $metadata, $version),
            ];
        } else {
            $documents[] = [
                'key' => 'rules_access_and_systems',
                'title' => 'Termo complementar de regras, encargos e entrega',
                'version' => $version,
                'content' => $this->rulesDocument($lease, $property, $metadata, $version),
            ];
        }

        return $documents;
    }

    private function contractTitle(string $template): string
    {
        return match ($template) {
            LeaseTemplateService::COMMERCIAL => 'Contrato de locação de imóvel comercial',
            LeaseTemplateService::SHARED_SUITE => 'Contrato de locação residencial de suíte em casa compartilhada',
            default => 'Contrato de locação de imóvel residencial',
        };
    }

    private function depositAcknowledgement(object $lease, object $property, array $metadata, int $version): string
    {
        $settings = data_get($metadata, 'contract', []);
        $months = (int) $lease->deposit_months;
        $depositMode = data_get($settings, 'deposit_mode', 'last_months_credit');
        $iptu = (float) data_get($settings, 'iptu_monthly_amount', 0);

        $parts = [
            'TERMO DE CIÊNCIA SOBRE A CAUÇÃO',
            'LOCATÁRIO: '.($lease->tenant_name ?: 'não informado').'.',
            'Imóvel: '.$this->propertyAddress($property).'.',
            'O LOCATÁRIO declara estar ciente de que foi registrada caução no valor de '.$this->money($lease->deposit_amount).", correspondente a {$months} aluguel(is).",
        ];

        $parts[] = $depositMode === 'last_months_credit'
            ? "Cumprido integralmente o prazo contratual e inexistindo débitos, danos ou outras pendências, a caução será utilizada para abatimento dos {$months} último(s) aluguel(is). Ela não poderá ser utilizada antecipadamente como substituição dos pagamentos mensais."
            : 'A caução será considerada no acerto financeiro final, após a apuração de débitos, danos, encargos, reparos e demais obrigações previstas na locação.';

        if ($iptu > 0) {
            $parts[] = 'O valor mensal de IPTU registrado separadamente, de '.$this->money($iptu).', não integra o abatimento da caução e continua devido enquanto houver obrigação contratual correspondente.';
        }

        $parts[] = 'Na hipótese de encerramento antecipado, a caução não se converte automaticamente em aluguel. Sua utilização dependerá da apuração das obrigações existentes e das condições de rescisão aplicáveis.';
        $parts[] = 'Débitos ou danos superiores ao valor caucionado permanecem de responsabilidade de quem lhes tiver dado causa, observada a comprovação e a legislação aplicável.';
        $parts[] = "Versão documental: {$version}. Este termo integra o mesmo pacote eletrônico do contrato principal.";

        return implode("\n\n", $parts);
    }

    private function sharedHouseRulesDocument(object $lease, object $property, array $metadata, int $version): string
    {
        $settings = data_get($metadata, 'contract', []);
        $rules = [
            'UNIDADE PRIVATIVA' => data_get($settings, 'exclusive_area', 'Suíte individual, com quarto e banheiro de uso exclusivo.'),
            'ÁREAS COMPARTILHADAS' => data_get($settings, 'shared_areas', 'Cozinha, sala, garagem e áreas de convivência.'),
            'VISITANTES E HÓSPEDES' => data_get($settings, 'visitors_policy'),
            'SOM, FESTAS E SILÊNCIO' => data_get($settings, 'noise_policy'),
            'ANIMAIS' => data_get($settings, 'pets_policy'),
            'FUMO' => data_get($settings, 'smoking_policy'),
            'LIMPEZA DAS ÁREAS COMUNS' => data_get($settings, 'common_area_policy'),
        ];

        $parts = [
            'TERMO DE CIÊNCIA DAS REGRAS DA CASA COMPARTILHADA',
            'LOCATÁRIO: '.($lease->tenant_name ?: 'não informado').'.',
            'Imóvel: '.$this->propertyAddress($property).'.',
            'O LOCATÁRIO reconhece que ocupa uma unidade privativa dentro de residência compartilhada e que as áreas comuns não são de uso exclusivo.',
        ];

        foreach ($rules as $label => $rule) {
            $rule = trim((string) $rule);
            if ($rule !== '') $parts[] = "{$label}: {$rule}";
        }

        if ((bool) data_get($settings, 'concurrent_tenants_allowed', true)) {
            $parts[] = 'Poderão existir outros moradores ou locatários simultaneamente no imóvel, cada qual com sua unidade ou condição de ocupação registrada. Todos deverão respeitar privacidade, segurança, limpeza, sossego e boa convivência.';
        }

        if ((bool) data_get($settings, 'daily_rate_enabled', false) && (float) data_get($settings, 'daily_rate_amount', 0) > 0) {
            $parts[] = 'Quando expressamente disponibilizada, a modalidade por diária terá valor de '.$this->money(data_get($settings, 'daily_rate_amount')).', sujeita a disponibilidade, reserva e pagamento nas condições acordadas.';
        }

        $included = $this->expenseNames($this->decode($lease->included_expenses));
        if ($included) $parts[] = 'Despesas incluídas no aluguel: '.$included.'.';
        $parts[] = "Versão documental: {$version}. Este termo integra o mesmo aceite eletrônico do contrato principal.";

        return implode("\n\n", $parts);
    }

    private function securityAddendum(object $lease, object $property, array $metadata, int $version): string
    {
        $tenant = $lease->tenant_name ?: 'LOCATÁRIO';
        $landlord = DB::table('users')->where('id', $lease->landlord_user_id)->first();
        $landlordName = trim(($landlord->first_name ?? '').' '.($landlord->last_name ?? '')) ?: ($landlord->name ?? 'LOCADOR');

        return implode("\n\n", [
            'ADITIVO AO CONTRATO DE LOCAÇÃO PARA INSTALAÇÃO E MANUTENÇÃO DE SISTEMAS DE SEGURANÇA',
            "Este aditivo é firmado entre {$landlordName}, na qualidade de LOCADOR, e {$tenant}, na qualidade de LOCATÁRIO, referente ao imóvel situado em {$this->propertyAddress($property)}.",
            "CLÁUSULA 1ª – DA INSTALAÇÃO\nO LOCADOR poderá manter, instalar ou substituir câmeras e outros equipamentos destinados à segurança, desde que as intervenções evitem danos desnecessários à estrutura e respeitem as condições da locação.",
            "CLÁUSULA 2ª – DA MANUTENÇÃO\nO LOCADOR será responsável pela manutenção dos sistemas por ele instalados, salvo dano comprovadamente provocado pelo LOCATÁRIO ou por terceiro sob sua responsabilidade.",
            "CLÁUSULA 3ª – DO ACESSO\nQuando houver necessidade de acesso à unidade ou ao imóvel para manutenção, o LOCADOR deverá comunicar previamente o LOCATÁRIO e combinar data e horário, ressalvadas situações emergenciais devidamente justificadas.",
            "CLÁUSULA 4ª – DA PRIVACIDADE E PROTEÇÃO DE DADOS\nOs sistemas de segurança serão utilizados para proteção de pessoas e patrimônio. É vedada a instalação de câmeras em quartos, banheiros ou outros locais destinados à intimidade pessoal, e o acesso às imagens deverá ser restrito às finalidades legítimas de segurança, manutenção ou cumprimento de obrigação legal.",
            "Versão documental: {$version}. Permanecem válidas as demais condições do contrato principal que não conflitarem com este aditivo.",
        ]);
    }

    private function inspectionDocument(object $lease, object $property, array $metadata, int $version): string
    {
        $address = $this->propertyAddress($property);
        $inspectionRequired = data_get($metadata, 'contract.inspection_required', true);
        $tenant = $lease->tenant_name ?: 'LOCATÁRIO';
        $landlord = DB::table('users')->where('id', $lease->landlord_user_id)->first();
        $landlordName = trim(($landlord->first_name ?? '').' '.($landlord->last_name ?? '')) ?: ($landlord->name ?? 'LOCADOR');
        $shared = data_get($metadata, 'contract.occupancy_type') === 'shared_unit';

        $parts = [
            $shared ? 'TERMO DE VISTORIA, OCUPAÇÃO E ENTREGA DE CHAVES' : 'TERMO DE VISTORIA E ENTREGA DO IMÓVEL',
            "Imóvel: {$address}.",
            "LOCADOR: {$landlordName}.\nLOCATÁRIO: {$tenant}.",
            $inspectionRequired
                ? 'As partes reconhecem que a vistoria inicial, com registros descritivos e/ou fotográficos vinculados à locação, integra este termo e servirá de referência para a devolução, ressalvado o desgaste natural decorrente do uso regular.'
                : 'As partes registram que a condição de entrega deverá ser documentada na plataforma antes da ocupação, podendo ser complementada por registros descritivos e fotográficos.',
            $shared
                ? 'Na locação compartilhada, a vistoria deverá identificar a unidade privativa, as chaves entregues, os itens de uso exclusivo e, quando necessário, o estado dos equipamentos ou mobiliários de áreas comuns cuja responsabilidade esteja vinculada ao LOCATÁRIO.'
                : 'Na devolução, serão conferidos conservação, limpeza, chaves, instalações, equipamentos, contas e demais obrigações registradas na locação.',
            'A entrega das chaves será registrada como marco operacional da devolução, sem impedir a apuração posterior de valores ou danos comprovadamente relacionados ao período de ocupação.',
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
        $iptu = (float) data_get($settings, 'iptu_monthly_amount', 0);
        $depositMode = data_get($settings, 'deposit_mode', 'last_months_credit');

        $parts = [
            'TERMO COMPLEMENTAR DE REGRAS, ENCARGOS E ENTREGA',
            'Este termo complementa o contrato principal e registra condições operacionais variáveis definidas para esta locação.',
            'Despesas sob responsabilidade do LOCATÁRIO: '.($tenantExpenses ?: 'as não expressamente incluídas pelo LOCADOR').'.',
            'Despesas incluídas no aluguel: '.($included ?: 'nenhuma despesa adicional registrada como incluída').'.',
        ];

        if ($iptu > 0) $parts[] = 'IPTU mensal registrado separadamente: '.$this->money($iptu).'.';
        if ((int) $lease->deposit_months > 0) {
            $parts[] = 'Caução registrada: '.$this->money($lease->deposit_amount).', correspondente a '.((int) $lease->deposit_months).' aluguel(is). Tratamento acordado: '.($depositMode === 'last_months_credit' ? 'abatimento nos últimos aluguéis, quando cumpridas as condições contratuais' : 'acerto financeiro ao encerramento da locação').'.';
        }

        $custom = collect($this->decode($lease->clauses))->map(function ($clause) {
            if (is_array($clause)) return trim((string) ($clause['text'] ?? ''));
            return trim((string) $clause);
        })->filter()->values();
        if ($custom->isNotEmpty()) $parts[] = "Condições adicionais acordadas:\n- ".$custom->implode("\n- ");

        $parts[] = 'Imóvel: '.$this->propertyAddress($property).'.';
        $parts[] = "Versão documental: {$version}. Este termo integra o mesmo aceite eletrônico do contrato e dos demais documentos desta versão.";

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
        if (! $value) return 'não informada';
        try { return \Carbon\CarbonImmutable::parse($value)->format('d/m/Y'); } catch (\Throwable) { return (string) $value; }
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
