<?php

namespace App\Domain\Leasing\Services;

use Illuminate\Support\Str;

final class LeaseTemplateService
{
    public const RESIDENTIAL = 'residential_reference_v1';
    public const COMMERCIAL = 'commercial_reference_v1';
    public const SHARED_SUITE = 'shared_suite_reference_v1';

    public function normalize(?string $template, string $purpose = 'residential'): string
    {
        $template = trim((string) $template);
        if (in_array($template, [self::RESIDENTIAL, self::COMMERCIAL, self::SHARED_SUITE], true)) {
            return $template;
        }

        return $purpose === 'commercial' ? self::COMMERCIAL : self::RESIDENTIAL;
    }

    public function renderContract(array $data): string
    {
        $template = $this->normalize($data['template'] ?? null, (string) ($data['purpose'] ?? 'residential'));

        return match ($template) {
            self::COMMERCIAL => $this->renderCommercial($data),
            self::SHARED_SUITE => $this->renderSharedSuite($data),
            default => $this->renderResidential($data),
        };
    }

    public function label(string $template): string
    {
        return match ($template) {
            self::COMMERCIAL => 'Comercial completo',
            self::SHARED_SUITE => 'Suíte/quarto em casa compartilhada',
            default => 'Residencial completo',
        };
    }

    private function renderResidential(array $d): string
    {
        return $this->renderWholeProperty($d, false);
    }

    private function renderCommercial(array $d): string
    {
        return $this->renderWholeProperty($d, true);
    }

    private function renderWholeProperty(array $d, bool $commercial): string
    {
        $settings = $d['settings'] ?? [];
        $lease = $d['lease'];
        $parts = [];
        $title = $commercial ? 'CONTRATO DE LOCAÇÃO DE IMÓVEL COMERCIAL' : 'CONTRATO DE LOCAÇÃO DE IMÓVEL RESIDENCIAL';
        $purpose = $commercial ? 'comerciais' : 'residenciais';

        $parts[] = $title;
        $parts[] = "Pelo presente instrumento particular, de um lado, {$d['landlord_qualification']}, doravante denominado LOCADOR; e, de outro lado, {$d['tenant_qualification']}, doravante denominado LOCATÁRIO, têm entre si justo e contratado o presente Contrato de Locação, mediante as cláusulas e condições seguintes.";

        $object = "CLÁUSULA 1ª – DO IMÓVEL LOCADO\nO LOCADOR dá em locação ao LOCATÁRIO o imóvel situado em {$d['property_address']}. O imóvel será utilizado exclusivamente para fins {$purpose}, sendo vedada finalidade diversa sem autorização prévia e expressa, por escrito, do LOCADOR.";
        if ($commercial) {
            $businessUse = trim((string) data_get($settings, 'business_use_description', ''));
            if ($businessUse !== '') {
                $object .= " A finalidade comercial autorizada é: {$businessUse}.";
            }
            if ((bool) data_get($settings, 'business_licenses_required', true)) {
                $object .= ' O LOCATÁRIO é responsável pela regularização cadastral da atividade e pela obtenção e manutenção de alvarás, licenças sanitárias, ambientais, municipais ou outras autorizações exigíveis para seu funcionamento.';
            }
        }
        $parts[] = $object;

        if ($d['inspection_required']) {
            $parts[] = 'O imóvel será entregue nas condições verificadas e registradas na vistoria inicial, que servirá como referência para a devolução, ressalvado o desgaste natural decorrente do uso regular.';
        }

        $parts = array_merge($parts, $this->commonFinancialAndLifecycleClauses($d, 2));

        if ($commercial) {
            $parts[] = "CLÁUSULA 10ª – DAS REFORMAS, INSTALAÇÕES E ATIVIDADE EMPRESARIAL\nQualquer obra, instalação, alteração de fachada, modificação estrutural ou adaptação relevante dependerá de autorização prévia e escrita do LOCADOR. O LOCATÁRIO responderá pela legalidade da sua atividade, pelos licenciamentos exigíveis e pelos danos decorrentes de instalações ou intervenções realizadas por si, seus colaboradores, fornecedores ou contratados.";
            $securityNumber = 11;
        } else {
            $securityNumber = 10;
        }

        if ($d['security_access']) {
            $parts[] = "CLÁUSULA {$securityNumber}ª – DOS SISTEMAS DE SEGURANÇA\nO LOCADOR poderá manter, instalar, substituir ou realizar manutenção de equipamentos destinados à segurança do imóvel, mediante comunicação prévia e acesso em condições razoáveis, evitando danos desnecessários à estrutura. As câmeras e demais equipamentos deverão respeitar a privacidade do LOCATÁRIO, sendo vedada sua instalação em locais destinados à intimidade pessoal.";
            $securityNumber++;
        }

        $parts = array_merge($parts, $this->additionalClauses($d['additional_clauses'] ?? [], $securityNumber));
        $parts[] = $this->forumAndSignatures($d);

        return implode("\n\n", array_filter($parts));
    }

    private function renderSharedSuite(array $d): string
    {
        $settings = $d['settings'] ?? [];
        $lease = $d['lease'];
        $exclusiveArea = trim((string) data_get($settings, 'exclusive_area', 'Suíte individual, composta por quarto e banheiro de uso exclusivo'));
        $sharedAreas = trim((string) data_get($settings, 'shared_areas', 'cozinha, sala, garagem e áreas de convivência'));
        $parts = [
            'CONTRATO DE LOCAÇÃO RESIDENCIAL DE SUÍTE EM CASA COMPARTILHADA',
            "Pelo presente instrumento particular, de um lado, {$d['landlord_qualification']}, doravante denominado LOCADOR; e, de outro lado, {$d['tenant_qualification']}, doravante denominado LOCATÁRIO, têm entre si justo e contratado o presente Contrato de Locação Residencial de Unidade em Imóvel Compartilhado.",
            "CLÁUSULA 1ª – DO OBJETO E DA OCUPAÇÃO COMPARTILHADA\nO objeto desta locação é {$exclusiveArea}, localizada no imóvel situado em {$d['property_address']}. A unidade privativa é destinada exclusivamente à moradia do LOCATÁRIO. São áreas de uso compartilhado: {$sharedAreas}.",
        ];

        if ((bool) data_get($settings, 'concurrent_tenants_allowed', true)) {
            $parts[] = 'O LOCATÁRIO declara ciência de que o imóvel poderá ser ocupado simultaneamente por outros moradores ou locatários em unidades distintas, sem exclusividade sobre as áreas comuns, devendo ser preservados privacidade, segurança, sossego e respeito mútuo.';
        }

        $parts[] = "CLÁUSULA 2ª – DO PRAZO\nA locação inicia-se em {$d['starts_on']} e encerra-se em {$d['ends_on']}, podendo ser renovada mediante acordo escrito entre as partes. A devolução da unidade privativa e das chaves deverá ocorrer até o encerramento da locação.";
        $parts[] = "CLÁUSULA 3ª – DO ALUGUEL E PAGAMENTO\nO aluguel mensal será de {$d['rent']}, com vencimento no dia {$lease->due_day} de cada mês, por PIX, transferência ou outro meio acordado. O pagamento somente será considerado quitado após a efetiva compensação.";
        if ($d['included_expenses']) $parts[] = 'Estão incluídas no aluguel as seguintes despesas: '.$d['included_expenses'].'.';
        if ($d['tenant_expenses']) $parts[] = 'Permanecem sob responsabilidade do LOCATÁRIO: '.$d['tenant_expenses'].'.';

        if ((bool) data_get($settings, 'daily_rate_enabled', false) && (float) data_get($settings, 'daily_rate_amount', 0) > 0) {
            $parts[] = 'CLÁUSULA 4ª – DA LOCAÇÃO ALTERNATIVA POR DIÁRIA'."\n".'Quando expressamente disponibilizada e acordada entre as partes, a unidade poderá ser utilizada por diária no valor de '.$this->money(data_get($settings, 'daily_rate_amount')).', com reserva e pagamento antecipado nas condições registradas na plataforma.';
            $number = 5;
        } else {
            $number = 4;
        }

        $parts[] = "CLÁUSULA {$number}ª – DA CAUÇÃO E DO ACERTO FINAL\n".$this->depositText($d);
        $number++;

        $rules = [
            'visitantes e hóspedes' => data_get($settings, 'visitors_policy'),
            'som, festas e silêncio' => data_get($settings, 'noise_policy'),
            'animais' => data_get($settings, 'pets_policy'),
            'fumo' => data_get($settings, 'smoking_policy'),
            'limpeza e organização das áreas comuns' => data_get($settings, 'common_area_policy'),
        ];
        $rulesText = collect($rules)->filter(fn ($value) => trim((string) $value) !== '')
            ->map(fn ($value, $label) => Str::ucfirst($label).': '.trim((string) $value))
            ->implode("\n- ");
        $parts[] = "CLÁUSULA {$number}ª – DAS REGRAS DE CONVIVÊNCIA\nO uso das áreas privativas e comuns exige respeito aos demais moradores e cumprimento das regras registradas para a residência.".($rulesText ? "\n- {$rulesText}" : '');
        $number++;

        $parts[] = "CLÁUSULA {$number}ª – DAS OBRIGAÇÕES DO LOCATÁRIO\nO LOCATÁRIO deverá pagar pontualmente os valores devidos, conservar sua unidade e as áreas comuns, comunicar defeitos ou danos, utilizar os ambientes de forma responsável, não realizar obras sem autorização e responder por danos causados por si ou por pessoas sob sua responsabilidade.";
        $number++;
        $parts[] = "CLÁUSULA {$number}ª – DAS OBRIGAÇÕES DO LOCADOR\nO LOCADOR deverá entregar a unidade em condições de uso, assegurar o uso pacífico da unidade privativa durante a vigência e responder por reparos estruturais que não decorram de mau uso do LOCATÁRIO.";
        $number++;
        $parts[] = "CLÁUSULA {$number}ª – DA VISTORIA, DEVOLUÇÃO E ENTREGA DAS CHAVES\nA condição da unidade privativa e, quando aplicável, dos itens de uso compartilhado será documentada na entrada e na saída. Danos além do desgaste natural, contas, multas, reparos ou outras obrigações pendentes poderão ser apurados no encerramento e compensados na forma contratada.";
        $number++;
        $parts[] = "CLÁUSULA {$number}ª – DA RESCISÃO\nA rescisão antecipada deverá observar o aviso e as condições registrados na locação, a legislação aplicável e eventual multa proporcional quando cabível. A entrega das chaves não elimina obrigações comprovadamente constituídas durante a ocupação.";
        $number++;

        if ($d['security_access']) {
            $parts[] = "CLÁUSULA {$number}ª – DOS SISTEMAS DE SEGURANÇA E DA PRIVACIDADE\nO LOCADOR poderá instalar ou manter sistemas de segurança nas áreas permitidas e realizar manutenção mediante comunicação prévia quando houver necessidade de acesso. É vedada a captação de imagens no interior da unidade privativa, banheiros ou outros locais de intimidade.";
            $number++;
        }

        $parts = array_merge($parts, $this->additionalClauses($d['additional_clauses'] ?? [], $number));
        $parts[] = $this->forumAndSignatures($d);

        return implode("\n\n", array_filter($parts));
    }

    private function commonFinancialAndLifecycleClauses(array $d, int $start): array
    {
        $lease = $d['lease'];
        $n = $start;
        $parts = [];
        $parts[] = "CLÁUSULA {$n}ª – DO PRAZO DA LOCAÇÃO\nA locação terá prazo determinado, iniciando-se em {$d['starts_on']} e encerrando-se em {$d['ends_on']}. Ao término, o LOCATÁRIO deverá devolver o imóvel, suas chaves e itens integrantes nas condições contratuais. Caso haja interesse em renovação, recomenda-se manifestação até o {$d['renewal_notice_month']}º mês de vigência, ficando qualquer renovação condicionada à concordância das partes e formalização por escrito.";
        $n++;
        $parts[] = "CLÁUSULA {$n}ª – DO VALOR DO ALUGUEL E DAS CONDIÇÕES DE PAGAMENTO\nO aluguel mensal será de {$d['rent']}, com vencimento no dia {$lease->due_day} de cada mês, por PIX, transferência, depósito ou outro meio previamente acordado entre as partes. O pagamento somente será considerado quitado após sua efetiva compensação. O atraso sujeitará o responsável aos encargos previstos na legislação e nas condições adicionais pactuadas.";
        $n++;
        $parts[] = "CLÁUSULA {$n}ª – DA GARANTIA LOCATÍCIA\n".$this->depositText($d);
        $n++;
        $parts[] = "CLÁUSULA {$n}ª – DAS OBRIGAÇÕES DO LOCATÁRIO\nO LOCATÁRIO deverá pagar pontualmente o aluguel e os encargos sob sua responsabilidade, conservar o imóvel, manter limpeza e higiene, comunicar problemas relevantes e não realizar reformas, modificações ou alterações estruturais sem autorização prévia e expressa do LOCADOR.";
        if ($d['tenant_expenses']) $parts[] = 'Despesas sob responsabilidade do LOCATÁRIO: '.$d['tenant_expenses'].'.';
        $n++;
        $parts[] = "CLÁUSULA {$n}ª – DAS OBRIGAÇÕES DO LOCADOR\nO LOCADOR deverá entregar o imóvel em condições de uso compatíveis com a finalidade contratada e assegurar o uso e gozo pacífico durante a vigência, ressalvadas as responsabilidades do LOCATÁRIO por danos decorrentes de mau uso ou negligência.";
        if ($d['included_expenses']) $parts[] = 'Despesas expressamente incluídas no aluguel: '.$d['included_expenses'].'.';
        $n++;
        $parts[] = "CLÁUSULA {$n}ª – DA CONSERVAÇÃO, VISTORIA E DEVOLUÇÃO\nO LOCATÁRIO deverá devolver o imóvel no estado de conservação registrado na vistoria inicial, ressalvado o desgaste natural do uso regular. Danos, contas de consumo, multas, encargos, reparos ou outras obrigações de sua responsabilidade deverão ser quitados antes do encerramento definitivo da locação.";
        $n++;
        $frequency = (int) $lease->adjustment_frequency_months ?: 12;
        $index = $lease->adjustment_index ? ' pelo índice '.$lease->adjustment_index : '';
        $parts[] = "CLÁUSULA {$n}ª – DO REAJUSTE\nO aluguel poderá ser reajustado a cada {$frequency} meses{$index}, sempre observada a legislação aplicável na data do reajuste.";
        $n++;
        $parts[] = "CLÁUSULA {$n}ª – DA RESCISÃO E DA RENOVAÇÃO\nA rescisão antecipada, inadimplemento ou descumprimento das obrigações será tratado conforme este instrumento e a legislação aplicável. Qualquer alteração, renovação ou condição superveniente deverá ser formalizada por escrito, preservando-se o histórico das versões assinadas.";

        return $parts;
    }

    private function depositText(array $d): string
    {
        $lease = $d['lease'];
        $months = (int) $lease->deposit_months;
        if ($months <= 0) {
            return 'As partes declaram que esta locação não possui caução em dinheiro, sem prejuízo de outra garantia expressamente registrada nas condições da locação.';
        }

        $text = "A caução será de {$d['deposit']}, correspondente a {$months} aluguel(is).";
        if ($d['deposit_mode'] === 'last_months_credit') {
            $text .= " Cumprido integralmente o contrato até o termo final e inexistindo pendências, a caução será destinada ao abatimento dos {$months} último(s) aluguel(is), não podendo ser usada antecipadamente durante a vigência.";
        } else {
            $text .= ' Ao término da locação, sua destinação observará as pendências contratuais, os danos apurados e a legislação aplicável.';
        }
        if ($d['iptu_monthly'] > 0) {
            $text .= ' O abatimento da caução não alcança o reembolso mensal de IPTU no valor de '.$this->money($d['iptu_monthly']).', que permanece devido inclusive nos últimos meses.';
        }
        $text .= ' Em caso de desocupação antecipada, não haverá abatimento automático da caução como aluguel, devendo ser feita a apuração das obrigações pendentes e das condições de rescisão.';

        return $text;
    }

    private function additionalClauses(iterable $clauses, int $start): array
    {
        $parts = [];
        $index = 0;
        foreach ($clauses as $clause) {
            $text = trim((string) $clause);
            if ($text === '') continue;
            $parts[] = 'CLÁUSULA '.($start + $index)."ª – CONDIÇÃO ADICIONAL\n".$text;
            $index++;
        }

        return $parts;
    }

    private function forumAndSignatures(array $d): string
    {
        return "DO FORO\nFica indicado o foro de {$d['forum']} para as questões decorrentes deste contrato, observada a competência legal aplicável.\n\nE, por estarem de acordo, as partes firmam eletronicamente o presente instrumento.\n\nLOCADOR: {$d['landlord_name']}\nLOCATÁRIO: {$d['tenant_name']}\n\nDocumento gerado eletronicamente. Versão {$d['version']}. Modelo de referência: {$d['template']} ({$this->label($d['template'])}).";
    }

    private function money(mixed $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }
}
