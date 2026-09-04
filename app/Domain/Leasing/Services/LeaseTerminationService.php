<?php

namespace App\Domain\Leasing\Services;

use App\Domain\Documents\DTOs\DocumentAuditContext;
use App\Domain\Documents\Services\DocumentWorkflowService;
use App\Domain\Notifications\Services\NotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class LeaseTerminationService
{
    public function __construct(
        private readonly DocumentWorkflowService $documents,
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function state(int $appId, int $leaseId): array
    {
        $operation = DB::table('lease_operations')
            ->where('app_id', $appId)
            ->where('lease_id', $leaseId)
            ->where('type', 'termination')
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();
        $document = $this->documents->latestForContext($appId, 'lease', $leaseId, 'lease_termination');

        return [
            'termination' => $operation ? $this->decodeOperation($operation) : null,
            'document' => $document,
            'timeline' => $document ? $this->documents->timelineForApplication($appId, $document->id) : [],
        ];
    }

    public function complete(int $appId, string $appSlug, object $lease, array $data, int $actorUserId, DocumentAuditContext $auditContext): array
    {
        abort_if($lease->status === 'cancelled', 422, 'Uma locação cancelada não pode receber distrato.');

        $operation = DB::table('lease_operations')
            ->where('app_id', $appId)
            ->where('lease_id', $lease->id)
            ->where('type', 'termination')
            ->whereIn('status', ['open', 'in_progress'])
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->firstOrFail();

        $endedOn = CarbonImmutable::parse($data['ended_on'])->startOfDay();
        $keysReturnedOn = CarbonImmutable::parse($data['keys_returned_on'])->startOfDay();
        $startsOn = CarbonImmutable::parse($lease->starts_on)->startOfDay();
        abort_if($endedOn->lt($startsOn), 422, 'A data do distrato não pode ser anterior ao início da locação.');
        abort_if($keysReturnedOn->lt($startsOn), 422, 'A entrega das chaves não pode ser anterior ao início da locação.');
        abort_if($keysReturnedOn->gt($endedOn), 422, 'A entrega das chaves não pode ocorrer depois da data efetiva do distrato.');

        $property = DB::table('properties')
            ->where('app_id', $appId)
            ->where('id', $lease->property_id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $inspection = null;
        if (! empty($data['exit_inspection_id'])) {
            $inspection = DB::table('property_inspections')
                ->where('app_id', $appId)
                ->where('id', $data['exit_inspection_id'])
                ->where('property_id', $lease->property_id)
                ->where('lease_id', $lease->id)
                ->where('type', 'exit')
                ->first();
            abort_unless($inspection, 422, 'A vistoria de saída selecionada não pertence a esta locação.');
        }

        $amounts = [
            'final_charge_amount' => $this->amount($data['final_charge_amount'] ?? 0),
            'termination_penalty_amount' => $this->amount($data['termination_penalty_amount'] ?? 0),
            'damage_amount' => $this->amount($data['damage_amount'] ?? 0),
            'outstanding_amount' => $this->amount($data['outstanding_amount'] ?? 0),
            'deposit_refund_amount' => $this->amount($data['deposit_refund_amount'] ?? 0),
            'deposit_applied_amount' => $this->amount($data['deposit_applied_amount'] ?? 0),
        ];

        $mutualRelease = (bool) ($data['mutual_release'] ?? false);
        if ($mutualRelease) {
            abort_if($amounts['outstanding_amount'] > 0, 422, 'Não é possível declarar quitação recíproca enquanto houver saldo pendente informado.');
            abort_if($data['deposit_settlement'] === 'pending', 422, 'Não é possível declarar quitação recíproca enquanto o acerto da caução estiver pendente.');
        }

        $payload = $this->jsonDecode($operation->payload);
        $checklist = array_merge([
            'notice' => false,
            'final_charges' => false,
            'utilities' => false,
            'exit_inspection' => false,
            'keys' => false,
            'deposit' => false,
            'repairs' => false,
            'closing_term' => false,
        ], is_array($data['checklist']) ? $data['checklist'] : []);
        $checklist['keys'] = true;
        $checklist['closing_term'] = true;
        if ($inspection) {
            $checklist['exit_inspection'] = true;
        }

        $settlement = [
            'ended_on' => $endedOn->toDateString(),
            'reason' => trim((string) ($data['reason'] ?? $operation->description ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'keys' => [
                'returned' => true,
                'returned_on' => $keysReturnedOn->toDateString(),
                'quantity' => isset($data['keys_quantity']) ? (int) $data['keys_quantity'] : null,
                'notes' => trim((string) ($data['key_notes'] ?? '')) ?: null,
            ],
            'exit_inspection_id' => $inspection?->id,
            'exit_inspection_summary' => $inspection?->summary,
            'financial' => array_merge($amounts, ['deposit_settlement' => $data['deposit_settlement']]),
            'utilities_notes' => trim((string) ($data['utilities_notes'] ?? '')) ?: null,
            'mutual_release' => $mutualRelease,
            'checklist' => $checklist,
            'recorded_by_user_id' => $actorUserId,
            'recorded_at' => now()->toIso8601String(),
        ];

        $agreement = $this->documents->latestForContext($appId, 'lease', $lease->id, 'lease_agreement');
        $documentPayload = [
            'lease' => $this->decodeRow($lease, ['clauses', 'included_expenses', 'tenant_expenses', 'metadata']),
            'property' => $this->decodeRow($property, ['metadata']),
            'termination' => $settlement,
            'parent_agreement_public_id' => $agreement?->public_id,
        ];

        $document = $this->documents->createOrRevise(
            $appId,
            'lease',
            $lease->id,
            'lease_termination',
            'Distrato e entrega de chaves - '.$property->name,
            $this->buildDocument($lease, $property, $settlement, $inspection, $agreement),
            $documentPayload,
            $this->parties($lease),
            $actorUserId,
            $agreement?->id,
            $auditContext,
        );

        $settlement['document_id'] = $document->id;
        $settlement['document_public_id'] = $document->public_id;
        $settlement['document_version'] = $document->current_version;
        $payload = array_merge($payload, $settlement);

        DB::transaction(function () use ($appId, $lease, $operation, $payload, $settlement, $endedOn, $data) {
            DB::table('lease_operations')->where('id', $operation->id)->where('app_id', $appId)->update([
                'status' => 'completed',
                'payload' => $this->json($payload),
                'occurred_at' => $endedOn->toDateString(),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            $metadata = $this->jsonDecode($lease->metadata);
            $metadata['termination'] = $settlement;
            $metadata['workflow'] = array_merge($metadata['workflow'] ?? [], [
                'stage' => 'ended',
                'ended_at' => now()->toIso8601String(),
                'termination_document_public_id' => $settlement['document_public_id'],
            ]);

            DB::table('leases')->where('app_id', $appId)->where('id', $lease->id)->update([
                'status' => 'ended',
                'ended_at' => now(),
                'ends_on' => $endedOn->toDateString(),
                'metadata' => $this->json($metadata),
                'updated_at' => now(),
            ]);

            if ((bool) ($data['cancel_future_rent_charges'] ?? true)) {
                $futureCharges = DB::table('lease_charges')
                    ->where('app_id', $appId)
                    ->where('lease_id', $lease->id)
                    ->where('type', 'rent')
                    ->where('status', 'pending')
                    ->whereDate('due_date', '>', $endedOn->toDateString())
                    ->get();

                foreach ($futureCharges as $charge) {
                    $chargeMetadata = $this->jsonDecode($charge->metadata);
                    $chargeMetadata['cancelled_by_termination'] = [
                        'ended_on' => $endedOn->toDateString(),
                        'operation_id' => $operation->id,
                        'recorded_at' => now()->toIso8601String(),
                    ];
                    DB::table('lease_charges')->where('id', $charge->id)->where('app_id', $appId)->update([
                        'status' => 'cancelled',
                        'metadata' => $this->json($chargeMetadata),
                        'updated_at' => now(),
                    ]);
                }
            }

            $otherCurrentLease = DB::table('leases')
                ->where('app_id', $appId)
                ->where('property_id', $lease->property_id)
                ->where('id', '!=', $lease->id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->whereDate('starts_on', '<=', today())
                ->whereDate('ends_on', '>=', today())
                ->exists();

            if (! $otherCurrentLease) {
                DB::table('properties')
                    ->where('app_id', $appId)
                    ->where('id', $lease->property_id)
                    ->whereNotIn('status', ['maintenance', 'inactive'])
                    ->update(['status' => 'available', 'updated_at' => now()]);
            }
        });

        return [
            'ok' => true,
            'termination' => $this->decodeOperation(DB::table('lease_operations')->where('app_id', $appId)->find($operation->id)),
            'document' => $this->documents->payloadForApplication($appId, $document->id),
            'pdf_path' => '/v1/apps/'.$appSlug.'/documents/'.$document->public_id.'/pdf',
        ];
    }

    public function send(int $appId, int $leaseId, int $actorUserId, DocumentAuditContext $auditContext): array
    {
        $document = $this->documents->latestForContext($appId, 'lease', $leaseId, 'lease_termination');
        abort_if(! $document, 422, 'Gere o distrato antes de enviar para assinatura.');
        abort_if(in_array($document->status, ['signed', 'active'], true), 422, 'O distrato já foi integralmente assinado.');

        $baseUrl = $this->trustedApplicationUrl($appId);
        $sent = $this->documents->sendForApplication($appId, $document->id, $actorUserId, $auditContext);
        $recipients = [];

        try {
            foreach ($sent['signature_requests'] as $signatureRequest) {
                $party = $signatureRequest['party'];
                if (! $party->email) {
                    continue;
                }

                $link = $baseUrl.'/sign/'.$signatureRequest['token'];
                $this->notifications->sendEmail(
                    (string) $party->email,
                    $party->name ?: null,
                    'Distrato para assinatura eletrônica',
                    $this->invitationText($sent['document'], $link, $party->name),
                );
                $recipients[] = $this->maskEmail($party->email);
            }
        } catch (Throwable $e) {
            report($e);
            $this->documents->reopenForRevisionForApplication($appId, $document->id, $actorUserId, $auditContext);
            throw $e;
        }

        abort_if(empty($recipients), 422, 'Nenhuma das partes possui e-mail válido para receber o distrato.');

        return [
            'ok' => true,
            'sent_to' => array_values(array_unique($recipients)),
            'document' => $this->documents->payloadForApplication($appId, $document->id),
        ];
    }

    public function timeline(int $appId, int $leaseId): array
    {
        $document = $this->documents->latestForContext($appId, 'lease', $leaseId, 'lease_termination');

        return $document ? $this->documents->timelineForApplication($appId, $document->id) : [];
    }

    private function buildDocument(object $lease, object $property, array $settlement, ?object $inspection, ?object $agreement): string
    {
        $landlord = DB::table('users')->where('id', $lease->landlord_user_id)->firstOrFail();
        $landlordName = $this->userName($landlord, 'LOCADOR');
        $tenantName = trim((string) $lease->tenant_name) ?: 'LOCATÁRIO';
        $propertyAddress = $this->propertyAddress($property);
        $financial = $settlement['financial'];
        $keys = $settlement['keys'];

        $parts = [
            'INSTRUMENTO PARTICULAR DE DISTRATO DE LOCAÇÃO E TERMO DE ENTREGA DE CHAVES',
            "Pelo presente instrumento particular, de um lado, {$landlordName}, doravante denominado LOCADOR, e, de outro, {$tenantName}, doravante denominado LOCATÁRIO, resolvem formalizar o encerramento da relação locatícia referente ao imóvel situado em {$propertyAddress}.",
            'REFERÊNCIA CONTRATUAL',
            'A locação foi originalmente pactuada para o período de '.$this->date($lease->starts_on).' a '.$this->date($lease->ends_on).($agreement ? ' O contrato eletrônico de referência possui identificador '.$agreement->public_id.', versão '.$agreement->current_version.'.' : '').' Este distrato não apaga o histórico contratual nem as obrigações constituídas durante a vigência.',
            'CLÁUSULA 1ª – DO ENCERRAMENTO',
            'As partes registram o encerramento da locação em '.$this->date($settlement['ended_on']).'.'.($settlement['reason'] ? ' Motivo informado: '.$settlement['reason'].'.' : '').' A partir desta data cessa a posse contratual do LOCATÁRIO, ressalvadas obrigações financeiras, reparos, consumos, encargos e demais pendências expressamente indicadas neste instrumento.',
            'CLÁUSULA 2ª – DA DESOCUPAÇÃO E ENTREGA DAS CHAVES',
            'O LOCATÁRIO declara ter desocupado o imóvel e entregue as chaves ao LOCADOR em '.$this->date($keys['returned_on']).($keys['quantity'] ? ', totalizando '.$keys['quantity'].' chave(s)' : '').'.'.($keys['notes'] ? ' Observações sobre chaves, controles e acessos: '.$keys['notes'].'.' : '').' A entrega das chaves formaliza a restituição da posse, sem prejuízo da apuração de danos ou débitos já existentes.',
            'CLÁUSULA 3ª – DA VISTORIA DE SAÍDA E CONSERVAÇÃO',
            $inspection
                ? 'A vistoria de saída nº '.$inspection->id.', realizada em '.$this->dateTime($inspection->occurred_at).', integra a documentação do encerramento. Registro resumido: '.($inspection->summary ?: 'sem observações textuais adicionais').'.'
                : 'Não foi vinculada uma vistoria de saída específica a este distrato. As partes reconhecem que eventuais registros fotográficos, relatórios e ocorrências previamente anexados à locação permanecem preservados no histórico documental.',
            'CLÁUSULA 4ª – DO ACERTO FINANCEIRO',
            'Para fins de registro do encerramento, foram informados os seguintes valores: cobrança final '.$this->money($financial['final_charge_amount']).'; multa/rescisão '.$this->money($financial['termination_penalty_amount']).'; danos ou reparos '.$this->money($financial['damage_amount']).'; saldo ainda pendente '.$this->money($financial['outstanding_amount']).'; caução devolvida '.$this->money($financial['deposit_refund_amount']).'; caução utilizada no acerto '.$this->money($financial['deposit_applied_amount']).'. Situação da caução: '.$this->depositLabel($financial['deposit_settlement']).'.',
            'CLÁUSULA 5ª – DAS CONTAS, CONSUMOS E ENCARGOS',
            $settlement['utilities_notes']
                ? 'Fica registrado quanto a água, energia, condomínio, IPTU, internet e demais consumos/encargos: '.$settlement['utilities_notes'].'.'
                : 'As partes deverão observar as responsabilidades previstas no contrato quanto a água, energia, condomínio, IPTU, internet e demais consumos ou encargos. Valores ainda não faturados na data do distrato permanecem sujeitos à apuração conforme a responsabilidade contratual.',
        ];

        if ($settlement['mutual_release']) {
            $parts[] = 'CLÁUSULA 6ª – DA QUITAÇÃO RECÍPROCA';
            $parts[] = 'Considerando os valores registrados neste instrumento e a inexistência de saldo pendente informado, as partes declaram quitação recíproca quanto às obrigações conhecidas e apuradas até esta data, sem alcançar fatos ocultos, cobranças supervenientes de terceiros ou responsabilidades que, por sua natureza, somente possam ser identificadas posteriormente.';
        } else {
            $parts[] = 'CLÁUSULA 6ª – DAS PENDÊNCIAS E DA AUSÊNCIA DE QUITAÇÃO AUTOMÁTICA';
            $parts[] = 'A assinatura deste distrato não representa quitação automática de valores, danos, reparos, consumos ou encargos que estejam indicados como pendentes, que sejam comprovadamente anteriores à entrega das chaves ou que somente se tornem conhecidos após a leitura/faturamento final. A baixa definitiva dessas pendências deverá ser documentada quando ocorrer.';
        }

        $parts[] = 'CLÁUSULA 7ª – DO HISTÓRICO E DAS EVIDÊNCIAS';
        $parts[] = 'O contrato original, seus aditivos, comprovantes, cobranças, pagamentos, vistorias, arquivos, registros de entrega de chaves e assinaturas eletrônicas permanecem vinculados ao histórico da locação para fins de rastreabilidade e comprovação.';
        if ($settlement['notes']) {
            $parts[] = 'OBSERVAÇÕES FINAIS';
            $parts[] = $settlement['notes'];
        }
        $parts[] = 'ASSINATURAS';
        $parts[] = "E, por estarem de acordo com o encerramento e com os registros acima, as partes firmam eletronicamente este instrumento.\n\nLOCADOR: {$landlordName}\nLOCATÁRIO: {$tenantName}\n\nDocumento gerado eletronicamente e sujeito à trilha de auditoria da plataforma.";

        return implode("\n\n", array_filter($parts, fn ($part) => $part !== null && trim((string) $part) !== ''));
    }

    private function parties(object $lease): array
    {
        $landlord = DB::table('users')->where('id', $lease->landlord_user_id)->firstOrFail();

        return [
            [
                'role' => 'landlord',
                'user_id' => $lease->landlord_user_id,
                'name' => $this->userName($landlord, 'Locador'),
                'email' => $landlord->email ?? null,
                'tax_id' => $landlord->cpf ?? null,
                'signing_order' => 1,
            ],
            [
                'role' => 'tenant',
                'user_id' => $lease->tenant_user_id,
                'name' => $lease->tenant_name,
                'email' => $lease->tenant_email,
                'tax_id' => $lease->tenant_tax_id,
                'signing_order' => 2,
            ],
        ];
    }

    private function trustedApplicationUrl(int $appId): string
    {
        $url = rtrim((string) DB::table('applications')->where('id', $appId)->value('url'), '/');
        abort_if(! $url || ! filter_var($url, FILTER_VALIDATE_URL), 422, 'Configure uma URL pública válida para a aplicação antes de enviar documentos para assinatura.');
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        abort_if($scheme !== 'https' && ! in_array($host, ['localhost', '127.0.0.1'], true), 422, 'A URL pública da aplicação precisa usar HTTPS.');

        return $url;
    }

    private function invitationText(object $document, string $link, string $name): string
    {
        return "Olá, {$name}.\n\nO distrato da locação e o termo de entrega de chaves estão prontos para sua assinatura eletrônica.\n\nDocumento: {$document->title}\nVersão: {$document->current_version}\nLink seguro: {$link}\n\nConfira especialmente a data de encerramento, a entrega das chaves, a vistoria e o acerto financeiro antes de assinar.";
    }

    private function userName(object $user, string $fallback): string
    {
        return trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: ($user->name ?? $fallback);
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

    private function depositLabel(string $value): string
    {
        return match ($value) {
            'refunded' => 'devolvida ao locatário',
            'applied' => 'utilizada no acerto das obrigações',
            'retained' => 'retida conforme o acerto informado',
            'pending' => 'acerto ainda pendente',
            default => 'não aplicável',
        };
    }

    private function amount(mixed $value): float
    {
        return round(max(0, (float) $value), 2);
    }

    private function money(mixed $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    private function date(mixed $value): string
    {
        if (! $value) {
            return 'não informada';
        }

        try {
            return CarbonImmutable::parse($value)->format('d/m/Y');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function dateTime(mixed $value): string
    {
        if (! $value) {
            return 'não informada';
        }

        try {
            return CarbonImmutable::parse($value)->format('d/m/Y H:i');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function decodeRow(object $row, array $jsonColumns): array
    {
        $data = (array) $row;
        foreach ($jsonColumns as $column) {
            if (array_key_exists($column, $data)) {
                $data[$column] = $this->jsonDecode($data[$column]);
            }
        }

        return $data;
    }

    private function decodeOperation(object $row): object
    {
        $row->payload = $this->jsonDecode($row->payload);
        $row->amount = $row->amount === null ? null : (float) $row->amount;

        return $row;
    }

    private function jsonDecode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        if (! $value) {
            return [];
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function json(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, min(2, mb_strlen($local))).'***@'.$domain;
    }
}
