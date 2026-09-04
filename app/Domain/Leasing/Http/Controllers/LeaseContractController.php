<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LeaseContractController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

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
        $template = data_get($metadata, 'contract.template', $lease->purpose === 'residential' ? 'residential_reference_v1' : 'standard_v1');
        $version = $lease->contract_generated_at ? ((int) $lease->contract_version + 1) : max(1, (int) $lease->contract_version);

        $contract = $this->build($lease, $property, $landlord, $metadata, $version, $template);

        DB::transaction(function () use ($leaseId, $contract, $version, $metadata, $template) {
            $metadata['contract']['template'] = $template;
            $metadata['contract']['generated_from_profile'] = true;
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
        $isAdmin = method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
        abort_unless((int) $lease->landlord_user_id === (int) $request->user()->id || $isAdmin, 403);
        return $lease;
    }

    private function build(object $lease, object $property, object $landlord, array $metadata, int $version, string $template): string
    {
        $tenant = data_get($metadata, 'tenant_profile', []);
        $settings = data_get($metadata, 'contract', []);
        $landlordProfile = $this->landlordProfile($landlord);

        $landlordName = $landlordProfile['name'];
        $landlordQualification = $this->qualification($landlordProfile, 'LOCADOR');
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
        $tenantQualification = $this->qualification($tenantProfile, 'LOCATÁRIO');
        $propertyAddress = $this->propertyAddress($property);
        $rent = $this->money($lease->rent_amount);
        $deposit = $this->money($lease->deposit_amount);
        $purpose = $lease->purpose === 'commercial' ? 'comerciais' : ($lease->purpose === 'mixed' ? 'mistos' : 'residenciais');
        $included = $this->expenseNames($this->decode($lease->included_expenses));
        $tenantExpenses = $this->expenseNames($this->decode($lease->tenant_expenses));
        $depositMonths = (int) $lease->deposit_months;
        $depositMode = data_get($settings, 'deposit_mode', 'last_months_credit');
        $renewalNoticeMonth = (int) data_get($settings, 'renewal_notice_month', 10);
        $iptuMonthly = (float) data_get($settings, 'iptu_monthly_amount', 0);
        $inspectionRequired = (bool) data_get($settings, 'inspection_required', true);
        $securityAccess = (bool) data_get($settings, 'security_system_access', false);
        $forum = data_get($settings, 'forum', trim(($property->city ?? '').'/'.($property->state ?? '')));
        $additionalClauses = collect($this->decode($lease->clauses))->map(function ($clause) {
            if (is_array($clause)) return trim((string) ($clause['text'] ?? ''));
            return trim((string) $clause);
        })->filter()->values();

        $parts = [];
        $parts[] = 'CONTRATO DE LOCAÇÃO DE IMÓVEL '.($lease->purpose === 'commercial' ? 'COMERCIAL' : 'RESIDENCIAL');
        $parts[] = "Pelo presente instrumento particular, de um lado, {$landlordQualification}, doravante denominado LOCADOR; e, de outro lado, {$tenantQualification}, doravante denominado LOCATÁRIO, têm entre si justo e contratado o presente Contrato de Locação, mediante as cláusulas e condições seguintes.";

        $parts[] = "CLÁUSULA 1ª – DO IMÓVEL LOCADO\nO LOCADOR dá em locação ao LOCATÁRIO o imóvel situado em {$propertyAddress}. O imóvel será utilizado exclusivamente para fins {$purpose}, sendo vedada finalidade diversa sem autorização prévia e expressa, por escrito, do LOCADOR.";
        if ($inspectionRequired) {
            $parts[] = 'O imóvel será entregue nas condições verificadas e registradas na vistoria inicial, que servirá como referência para a devolução, ressalvado o desgaste natural decorrente do uso regular.';
        }

        $parts[] = "CLÁUSULA 2ª – DO PRAZO DA LOCAÇÃO\nA locação terá prazo determinado, iniciando-se em {$this->date($lease->starts_on)} e encerrando-se em {$this->date($lease->ends_on)}. Ao término, o LOCATÁRIO deverá devolver o imóvel, suas chaves e itens integrantes nas condições contratuais. Caso haja interesse em renovação, recomenda-se manifestação até o {$renewalNoticeMonth}º mês de vigência, ficando qualquer renovação condicionada à concordância das partes e formalização por escrito.";

        $parts[] = "CLÁUSULA 3ª – DO VALOR DO ALUGUEL E DAS CONDIÇÕES DE PAGAMENTO\nO aluguel mensal será de {$rent}, com vencimento no dia {$lease->due_day} de cada mês, por PIX, transferência, depósito ou outro meio previamente acordado entre as partes. O pagamento somente será considerado quitado após sua efetiva compensação.";
        $parts[] = 'O atraso sujeitará o responsável aos encargos previstos na legislação e nas condições adicionais pactuadas, sem prejuízo da cobrança do valor devido e das medidas cabíveis em caso de inadimplência.';

        if ($depositMonths > 0) {
            $depositText = "A caução será de {$deposit}, correspondente a {$depositMonths} aluguel(is).";
            if ($depositMode === 'last_months_credit') {
                $depositText .= " Cumprido integralmente o contrato até o termo final e inexistindo pendências, a caução será destinada ao abatimento dos {$depositMonths} último(s) aluguel(is), não podendo ser usada antecipadamente durante a vigência.";
            } else {
                $depositText .= ' Ao término da locação, sua destinação observará as pendências contratuais, os danos apurados e a legislação aplicável.';
            }
            if ($iptuMonthly > 0) {
                $depositText .= ' O abatimento da caução não alcança o reembolso mensal de IPTU no valor de '.$this->money($iptuMonthly).', que permanece devido inclusive nos últimos meses.';
            }
            $parts[] = "CLÁUSULA 4ª – DA GARANTIA LOCATÍCIA\n{$depositText} Em caso de desocupação antecipada, não haverá abatimento automático da caução como aluguel, devendo ser feita a apuração das obrigações pendentes e das condições de rescisão.";
        } else {
            $parts[] = 'CLÁUSULA 4ª – DA GARANTIA LOCATÍCIA\nAs partes declaram que esta locação não possui caução em dinheiro, sem prejuízo de outra garantia expressamente registrada nas condições da locação.';
        }

        $parts[] = "CLÁUSULA 5ª – DAS OBRIGAÇÕES DO LOCATÁRIO\nO LOCATÁRIO deverá pagar pontualmente o aluguel e os encargos sob sua responsabilidade, conservar o imóvel, manter limpeza e higiene, comunicar problemas relevantes e não realizar reformas, modificações ou alterações estruturais sem autorização prévia e expressa do LOCADOR.";
        $parts[] = 'Despesas sob responsabilidade do LOCATÁRIO: '.($tenantExpenses ?: 'consumos e encargos não expressamente incluídos pelo LOCADOR').'.';

        $parts[] = "CLÁUSULA 6ª – DAS OBRIGAÇÕES DO LOCADOR\nO LOCADOR deverá entregar o imóvel em condições de uso compatíveis com a finalidade contratada e assegurar o uso e gozo pacífico durante a vigência, ressalvadas as responsabilidades do LOCATÁRIO por danos decorrentes de mau uso ou negligência.";
        if ($included) $parts[] = 'Despesas expressamente incluídas no aluguel: '.$included.'.';

        $parts[] = "CLÁUSULA 7ª – DA CONSERVAÇÃO, VISTORIA E DEVOLUÇÃO\nO LOCATÁRIO deverá devolver o imóvel no estado de conservação registrado na vistoria inicial, ressalvado o desgaste natural do uso regular. Danos, contas de consumo, multas, encargos, reparos ou outras obrigações de sua responsabilidade deverão ser quitados antes do encerramento definitivo da locação.";

        $parts[] = "CLÁUSULA 8ª – DO REAJUSTE\nO aluguel poderá ser reajustado a cada ".((int) $lease->adjustment_frequency_months ?: 12).' meses'.($lease->adjustment_index ? ' pelo índice '.$lease->adjustment_index : '').', sempre observada a legislação aplicável na data do reajuste.';

        $parts[] = "CLÁUSULA 9ª – DA RESCISÃO E DA RENOVAÇÃO\nA rescisão antecipada, inadimplemento ou descumprimento das obrigações será tratado conforme este instrumento e a legislação aplicável. Qualquer alteração, renovação ou condição superveniente deverá ser formalizada por escrito, preferencialmente por termo aditivo, preservando-se o histórico das versões assinadas.";

        if ($securityAccess) {
            $parts[] = "CLÁUSULA 10ª – DOS SISTEMAS DE SEGURANÇA\nO LOCADOR poderá manter, instalar, substituir ou realizar manutenção de equipamentos destinados à segurança do imóvel, mediante comunicação e acesso em condições razoáveis, evitando danos desnecessários à estrutura e respeitando a privacidade e a finalidade da locação.";
        }

        if ($additionalClauses->isNotEmpty()) {
            $offset = $securityAccess ? 11 : 10;
            foreach ($additionalClauses as $index => $clause) {
                $parts[] = 'CLÁUSULA '.$this->ordinal($offset + $index).' – CONDIÇÃO ADICIONAL\n'.$clause;
            }
        }

        $parts[] = "DO FORO\nFica indicado o foro de {$forum} para as questões decorrentes deste contrato, observada a competência legal aplicável.";
        $parts[] = "E, por estarem de acordo, as partes firmam eletronicamente o presente instrumento.\n\nLOCADOR: {$landlordName}\nLOCATÁRIO: {$lease->tenant_name}\n\nDocumento gerado eletronicamente. Versão {$version}. Modelo de referência: {$template}.";

        return implode("\n\n", array_filter($parts));
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
        if (! empty($profile['parent_1']) || ! empty($profile['parent_2'])) $details[] = 'filho(a) de '.implode(' e ', array_filter([$profile['parent_1'] ?? null, $profile['parent_2'] ?? null]));
        $address = trim(implode(', ', array_filter([$profile['address'] ?? null, $profile['city'] ?? null, $profile['state'] ?? null, $profile['postal_code'] ?? null])));
        if ($address) $details[] = 'residente e domiciliado(a) em '.$address;
        return $name.($details ? ', '.implode(', ', $details) : '');
    }

    private function documentLabel(array $profile): string
    {
        $type = trim((string) ($profile['document_type'] ?? 'documento de identidade')) ?: 'documento de identidade';
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
        $labels = ['iptu' => 'IPTU', 'water' => 'água', 'electricity' => 'energia elétrica', 'internet' => 'internet', 'condo' => 'condomínio'];
        return collect($values)->map(fn ($value) => $labels[$value] ?? Str::headline((string) $value))->implode(', ');
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
        try { return \Carbon\CarbonImmutable::parse($value)->format('d/m/Y'); }
        catch (\Throwable) { return (string) $value; }
    }

    private function ordinal(int $number): string
    {
        return $number.'ª';
    }
}
