<?php

namespace App\Services;

use App\Models\Production;

class CutinappProducerContractService
{
    public const VERSION = '2026-09-02-v1';

    public function text(Production $production): string
    {
        $productionName = trim((string) ($production->name ?: 'Produção'));
        $document = trim((string) ($production->cnpj ?: 'não informado'));

        return <<<TEXT
TERMO DE ADESÃO DO PRODUTOR À CUTINAPP
Versão: {$this->version()}

PRODUTOR: {$productionName}
CNPJ/Documento da produção: {$document}

1. OBJETO
Este Termo regula o uso da Cutinapp pelo PRODUTOR para cadastrar, divulgar, organizar e comercializar eventos, ingressos, cortesias, produtos e demais itens relacionados aos seus eventos.

2. RESPONSABILIDADE DO PRODUTOR
O PRODUTOR declara que possui legitimidade para organizar os eventos cadastrados, responsabilizando-se pelas informações publicadas, atrações anunciadas, autorizações, licenças, alvarás, classificação indicativa, direitos autorais, tributos, obrigações trabalhistas, consumeristas e demais exigências legais aplicáveis à realização de cada evento.

3. INGRESSOS, VENDAS E ENTREGA
O PRODUTOR é responsável pela definição de preços, lotes, quantidades, regras de acesso e benefícios anunciados. A Cutinapp fornecerá infraestrutura tecnológica para cadastro, pagamento, emissão e validação dos ingressos, observadas as condições técnicas e operacionais da plataforma e dos provedores de pagamento integrados.

4. PAGAMENTOS E TAXAS
Quando houver vendas pagas, os pagamentos poderão ser processados por provedores terceiros, incluindo o Mercado Pago. O PRODUTOR autoriza a cobrança das taxas de plataforma informadas pela Cutinapp e reconhece que taxas do processador de pagamento, estornos, chargebacks e demais ajustes poderão impactar os valores líquidos recebidos.

5. CANCELAMENTOS, REEMBOLSOS E EVENTOS NÃO REALIZADOS
O PRODUTOR é responsável por comunicar alterações relevantes, adiamentos e cancelamentos. Quando aplicável, deverá colaborar com os procedimentos de reembolso aos compradores e manter recursos suficientes para restituições, estornos, contestações e demais obrigações relacionadas às vendas do evento.

6. CONTEÚDO E DIREITOS DE TERCEIROS
O PRODUTOR garante possuir autorização para utilizar nomes, marcas, imagens, músicas, vídeos, fotografias e demais conteúdos enviados à plataforma, responsabilizando-se por reclamações de terceiros decorrentes de uso indevido.

7. CONDUTA E SEGURANÇA
É vedado utilizar a Cutinapp para eventos ilícitos, fraudulentos, discriminatórios ou que coloquem participantes em risco. A plataforma poderá suspender publicações, vendas ou acessos em caso de indícios de fraude, violação legal, risco aos usuários ou descumprimento destes termos.

8. DADOS PESSOAIS
As partes se comprometem a tratar dados pessoais de participantes e compradores somente para finalidades legítimas relacionadas à operação do evento, observando a legislação de proteção de dados aplicável. O PRODUTOR não poderá utilizar dados obtidos pela plataforma para práticas abusivas, venda de cadastros ou finalidades incompatíveis com a relação estabelecida com os usuários.

9. DISPONIBILIDADE DA PLATAFORMA
A Cutinapp empregará esforços razoáveis para manter seus serviços disponíveis e seguros, mas poderá realizar manutenções, atualizações e interrupções necessárias. Serviços de terceiros, inclusive meios de pagamento, podem possuir indisponibilidades próprias.

10. REGISTROS E AUDITORIA
O PRODUTOR concorda que registros eletrônicos relacionados a esta adesão, incluindo usuário autenticado, data e hora, endereço IP, versão do termo, hash do documento e demais evidências técnicas, poderão ser mantidos para fins de segurança, auditoria, prevenção a fraudes e comprovação da manifestação de vontade.

11. ASSINATURA ELETRÔNICA
Ao marcar a opção de concordância e confirmar eletronicamente este Termo, o signatário declara que leu o conteúdo, possui poderes para representar a produção indicada e manifesta sua concordância integral. Uma cópia eletrônica será disponibilizada e enviada ao e-mail da conta responsável.

12. ATUALIZAÇÕES
Uma nova versão deste Termo poderá ser exigida quando houver mudanças relevantes nas condições comerciais, operacionais ou jurídicas. Eventos já realizados permanecem vinculados aos registros e condições vigentes no momento da respectiva operação.

13. DISPOSIÇÕES FINAIS
Este Termo não transfere à Cutinapp a responsabilidade pela produção física, artística, operacional ou legal do evento. Situações não previstas serão analisadas conforme a legislação brasileira aplicável e os demais termos e políticas da plataforma.

Ao assinar, o PRODUTOR confirma que leu, compreendeu e aceita integralmente este Termo de Adesão.
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
