<?php

namespace App\Services;

use App\Models\Production;
use App\Support\ApplicationContext;

final class ProducerAgreementService
{
    public const VERSION = '2026-09-02-v1';

    public function __construct(private readonly ApplicationContext $context) {}

    public function text(Production $organization): string
    {
        $application = $this->context->application();
        $applicationName = trim((string) ($application->name ?: 'Plataforma Peter Tecnet'));
        $organizationName = trim((string) ($organization->name ?: 'Organização'));
        $document = trim((string) ($organization->cnpj ?: 'não informado'));

        return <<<TEXT
TERMO DE ADESÃO E PRESTAÇÃO DE SERVIÇOS
Versão: {$this->version()}

PLATAFORMA: PETER TECNET, operadora da aplicação {$applicationName}, inscrita no CNPJ sob nº 42.595.409/0001-48, doravante denominada PLATAFORMA.
ORGANIZAÇÃO: {$organizationName}
CNPJ/Documento: {$document}

1. OBJETO
Este Termo regula o uso da aplicação pelo responsável da ORGANIZAÇÃO para cadastrar, divulgar, organizar e comercializar eventos, ingressos, produtos, serviços e demais itens habilitados pelas capacidades da plataforma.

2. RESPONSABILIDADE DA ORGANIZAÇÃO
A ORGANIZAÇÃO declara possuir legitimidade para as operações cadastradas e que o signatário possui poderes para representá-la. É responsável pelas informações publicadas, autorizações, licenças, tributos, obrigações trabalhistas, consumeristas, de segurança e demais exigências legais aplicáveis.

3. OFERTAS, VENDAS E ENTREGA
A ORGANIZAÇÃO é responsável pela definição de preços, quantidades, regras de acesso, benefícios e condições comerciais. A PLATAFORMA fornece infraestrutura tecnológica para cadastro, pagamento, emissão e validação, conforme as capacidades habilitadas e as condições dos provedores integrados.

4. PAGAMENTOS E TAXAS
Quando houver vendas pagas, os pagamentos poderão ser processados por provedores terceiros. A ORGANIZAÇÃO autoriza a cobrança das taxas informadas pela PLATAFORMA e reconhece que taxas do processador, estornos, chargebacks, reembolsos e ajustes podem impactar os valores líquidos.

5. CANCELAMENTOS E REEMBOLSOS
A ORGANIZAÇÃO é responsável por comunicar alterações e cancelamentos e, quando aplicável, colaborar com procedimentos de reembolso e manter recursos suficientes para restituições, contestações e demais obrigações relacionadas às vendas.

6. CONTEÚDO E DIREITOS DE TERCEIROS
A ORGANIZAÇÃO garante possuir autorização para utilizar nomes, marcas, imagens, músicas, vídeos, fotografias e demais conteúdos enviados à PLATAFORMA.

7. CONDUTA, FRAUDE E SEGURANÇA
É vedado utilizar a PLATAFORMA para atividades ilícitas, fraudulentas, discriminatórias ou que coloquem usuários em risco. A PLATAFORMA poderá suspender publicações, vendas, repasses ou acessos em caso de indícios de fraude, violação legal, risco ou descumprimento deste Termo.

8. DADOS PESSOAIS
As partes tratarão dados pessoais somente para finalidades legítimas e compatíveis com a operação, observando a legislação de proteção de dados aplicável.

9. DISPONIBILIDADE
A PLATAFORMA empregará esforços razoáveis para manter os serviços disponíveis e seguros, podendo realizar manutenções, atualizações e interrupções necessárias. Serviços de terceiros podem possuir indisponibilidades próprias.

10. REGISTROS E AUDITORIA
Registros eletrônicos relacionados a esta adesão, incluindo usuário, data e hora, endereço IP, agente do navegador, versão do termo e hash do documento, poderão ser mantidos para segurança, auditoria, prevenção a fraude e comprovação da manifestação de vontade.

11. ASSINATURA ELETRÔNICA
Ao confirmar eletronicamente este Termo, o signatário declara que leu o conteúdo, possui poderes para representar a ORGANIZAÇÃO e manifesta concordância integral. Uma cópia eletrônica será disponibilizada ao responsável.

12. VIGÊNCIA E ATUALIZAÇÕES
Este Termo entra em vigor na data de aceitação e permanece aplicável enquanto a ORGANIZAÇÃO utilizar a aplicação. Nova versão poderá exigir nova aceitação quando houver mudanças relevantes.

13. ENCERRAMENTO
O encerramento do uso não elimina obrigações já constituídas, inclusive relacionadas a clientes, reembolsos, contestações, tributos, chargebacks e operações já comercializadas.

14. DISPOSIÇÕES FINAIS
Este Termo não transfere à PLATAFORMA a responsabilidade física, artística, operacional ou legal das atividades da ORGANIZAÇÃO. Situações não previstas serão analisadas conforme a legislação brasileira, as regras dos provedores integrados e os demais termos vigentes.

Ao assinar, o responsável confirma que leu, compreendeu e aceita integralmente este Termo.
TEXT;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
