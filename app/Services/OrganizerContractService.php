<?php

namespace App\Services;

use App\Models\Production;
use App\Support\ApplicationContext;

class OrganizerContractService
{
    public const VERSION = '2026-09-02-v1';

    public function __construct(private readonly ApplicationContext $context) {}

    public function text(Production $production): string
    {
        $application = $this->context->application()->name ?: $this->context->slug();
        $organizer = trim((string) ($production->name ?: 'Organização responsável'));
        $document = trim((string) ($production->cnpj ?: 'não informado'));

        return <<<TEXT
TERMO DE ADESÃO E PRESTAÇÃO DE SERVIÇOS
Versão: {$this->version()}

PLATAFORMA: PETER TECNET, operadora da aplicação {$application}, inscrita no CNPJ sob nº 42.595.409/0001-48.
ORGANIZADOR: {$organizer}
CNPJ/Documento: {$document}

1. OBJETO
Este Termo regula o uso da plataforma pelo ORGANIZADOR para cadastrar, divulgar, organizar e comercializar eventos, credenciais, cortesias, produtos e demais itens relacionados aos seus eventos.

2. RESPONSABILIDADE DO ORGANIZADOR
O ORGANIZADOR declara possuir legitimidade para organizar os eventos cadastrados e que o signatário possui poderes para representá-lo. É responsável pelas informações publicadas, atrações, autorizações, licenças, alvarás, classificação indicativa, direitos autorais, tributos, obrigações trabalhistas, consumeristas e de segurança aplicáveis.

3. VENDAS, CREDENCIAIS E ENTREGA
O ORGANIZADOR define preços, lotes, quantidades, regras de acesso e benefícios. A plataforma fornece infraestrutura tecnológica para cadastro, pagamento, emissão e validação das credenciais, conforme disponibilidade técnica e dos provedores integrados.

4. PAGAMENTOS E TAXAS
Pagamentos podem ser processados por provedores terceiros. O ORGANIZADOR autoriza as taxas de plataforma informadas e reconhece que taxas do processador, estornos, chargebacks, reembolsos e ajustes podem impactar os valores líquidos recebidos.

5. CANCELAMENTOS E REEMBOLSOS
O ORGANIZADOR deve comunicar alterações, adiamentos e cancelamentos e colaborar com reembolsos, mantendo recursos suficientes para restituições, estornos, contestações e demais obrigações das vendas.

6. CONTEÚDO E DIREITOS
O ORGANIZADOR garante autorização para utilizar nomes, marcas, imagens, músicas, vídeos, fotografias e demais conteúdos enviados, responsabilizando-se por reclamações de terceiros.

7. CONDUTA, FRAUDE E SEGURANÇA
É vedado usar a plataforma para atividades ilícitas, fraudulentas, discriminatórias ou que coloquem pessoas em risco. A Peter Tecnet poderá suspender publicações, vendas, repasses ou acessos diante de indícios de fraude, violação legal, risco ou descumprimento deste Termo.

8. DADOS PESSOAIS
As partes tratarão dados pessoais somente para finalidades legítimas da operação, observando a legislação aplicável de proteção de dados.

9. DISPONIBILIDADE
A Peter Tecnet empregará esforços razoáveis para manter seus serviços disponíveis e seguros, podendo realizar manutenções e atualizações. Serviços de terceiros possuem disponibilidade própria.

10. REGISTROS E AUDITORIA
Registros eletrônicos de usuário, data, hora, IP, navegador, versão do termo, hash e demais evidências técnicas poderão ser mantidos para segurança, auditoria, prevenção a fraudes e comprovação da manifestação de vontade.

11. ASSINATURA ELETRÔNICA
Ao aceitar eletronicamente este Termo, o signatário declara leitura, poderes de representação e concordância integral. As partes reconhecem a validade dos registros eletrônicos produzidos pela plataforma.

12. VIGÊNCIA E ATUALIZAÇÕES
Este Termo entra em vigor na aceitação e permanece aplicável durante o uso da plataforma. Nova versão poderá exigir nova aceitação quando houver mudanças relevantes.

13. ENCERRAMENTO
O encerramento do uso não elimina obrigações já constituídas, inclusive perante compradores, reembolsos, contestações, tributos, chargebacks e eventos comercializados.

14. DISPOSIÇÕES FINAIS
Este Termo não transfere à Peter Tecnet a responsabilidade pela produção física, artística, operacional ou legal do evento. Aplicam-se a legislação brasileira e as regras dos provedores integrados.

Ao assinar, o ORGANIZADOR confirma que leu, compreendeu e aceita integralmente este Termo.
TEXT;
    }

    public function version(): string { return self::VERSION; }
    public function hash(string $text): string { return hash('sha256', $text); }
}
