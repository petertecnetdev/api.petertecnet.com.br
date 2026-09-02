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
TERMO DE ADESÃO E PRESTAÇÃO DE SERVIÇOS DA CUTINAPP
Versão: {$this->version()}

PLATAFORMA: PETER TECNET, operadora da Cutinapp, inscrita no CNPJ sob nº 42.595.409/0001-48, doravante denominada CUTINAPP.
PRODUTOR: {$productionName}
CNPJ/Documento da produção: {$document}

1. OBJETO
Este Termo regula o uso da Cutinapp pelo PRODUTOR para cadastrar, divulgar, organizar e comercializar eventos, ingressos, cortesias, produtos e demais itens relacionados aos seus eventos.

2. RESPONSABILIDADE DO PRODUTOR
O PRODUTOR declara que possui legitimidade para organizar os eventos cadastrados e que o signatário possui poderes para representá-lo. O PRODUTOR responsabiliza-se pelas informações publicadas, atrações anunciadas, autorizações, licenças, alvarás, classificação indicativa, direitos autorais, tributos, obrigações trabalhistas, consumeristas, de segurança e demais exigências legais aplicáveis à realização de cada evento.

3. INGRESSOS, VENDAS E ENTREGA
O PRODUTOR é responsável pela definição de preços, lotes, quantidades, regras de acesso e benefícios anunciados. A CUTINAPP fornecerá infraestrutura tecnológica para cadastro, pagamento, emissão e validação dos ingressos, observadas as condições técnicas e operacionais da plataforma e dos provedores de pagamento integrados.

4. PAGAMENTOS E TAXAS
Quando houver vendas pagas, os pagamentos poderão ser processados por provedores terceiros, incluindo o Mercado Pago. O PRODUTOR autoriza a cobrança das taxas de plataforma informadas pela CUTINAPP e reconhece que taxas do processador de pagamento, estornos, chargebacks, reembolsos e demais ajustes poderão impactar os valores líquidos recebidos.

5. CANCELAMENTOS, REEMBOLSOS E EVENTOS NÃO REALIZADOS
O PRODUTOR é responsável por comunicar alterações relevantes, adiamentos e cancelamentos. Quando aplicável, deverá colaborar com os procedimentos de reembolso aos compradores e manter recursos suficientes para restituições, estornos, contestações e demais obrigações relacionadas às vendas do evento.

6. CONTEÚDO E DIREITOS DE TERCEIROS
O PRODUTOR garante possuir autorização para utilizar nomes, marcas, imagens, músicas, vídeos, fotografias e demais conteúdos enviados à plataforma, responsabilizando-se por reclamações de terceiros decorrentes de uso indevido.

7. CONDUTA, FRAUDE E SEGURANÇA
É vedado utilizar a CUTINAPP para eventos ilícitos, fraudulentos, discriminatórios ou que coloquem participantes em risco. A plataforma poderá suspender publicações, vendas, repasses ou acessos em caso de indícios de fraude, violação legal, risco aos usuários, chargebacks relevantes ou descumprimento deste Termo, preservados os direitos dos compradores e as obrigações legais aplicáveis.

8. DADOS PESSOAIS
As partes se comprometem a tratar dados pessoais de participantes e compradores somente para finalidades legítimas relacionadas à operação do evento, observando a legislação de proteção de dados aplicável. O PRODUTOR não poderá utilizar dados obtidos pela plataforma para venda de cadastros, envio abusivo de comunicações ou finalidades incompatíveis com a relação estabelecida com os usuários.

9. DISPONIBILIDADE DA PLATAFORMA
A CUTINAPP empregará esforços razoáveis para manter seus serviços disponíveis e seguros, mas poderá realizar manutenções, atualizações e interrupções necessárias. Serviços de terceiros, inclusive meios de pagamento, podem possuir indisponibilidades próprias.

10. REGISTROS E AUDITORIA
O PRODUTOR concorda que registros eletrônicos relacionados a esta adesão, incluindo usuário autenticado, data e hora, endereço IP, agente do navegador, versão do termo, hash do documento e demais evidências técnicas, poderão ser mantidos para fins de segurança, auditoria, prevenção a fraudes e comprovação da manifestação de vontade.

11. ASSINATURA ELETRÔNICA
Ao marcar a opção de concordância e confirmar eletronicamente este Termo, o signatário declara que leu o conteúdo, possui poderes para representar a produção indicada e manifesta sua concordância integral. As partes reconhecem como válidos os registros eletrônicos produzidos pela plataforma para comprovação da autoria, integridade e manifestação de vontade. Uma cópia eletrônica será disponibilizada e enviada ao e-mail da conta responsável.

12. VIGÊNCIA, ATUALIZAÇÕES E NOVA ACEITAÇÃO
Este Termo entra em vigor na data de sua aceitação e permanece aplicável enquanto a produção utilizar a CUTINAPP, sem prejuízo das obrigações relativas a eventos, vendas e reembolsos já realizados. Uma nova versão poderá exigir nova aceitação quando houver mudanças relevantes nas condições comerciais, operacionais ou jurídicas.

13. ENCERRAMENTO
O PRODUTOR poderá deixar de utilizar a plataforma, mas o encerramento não elimina obrigações já constituídas, inclusive relativas a compradores, reembolsos, contestações, tributos, chargebacks e eventos já comercializados.

14. DISPOSIÇÕES FINAIS
Este Termo não transfere à CUTINAPP a responsabilidade pela produção física, artística, operacional ou legal do evento. Situações não previstas serão analisadas conforme a legislação brasileira aplicável, as regras dos provedores integrados e os demais termos e políticas vigentes da plataforma.

Ao assinar, o PRODUTOR confirma que leu, compreendeu e aceita integralmente este Termo de Adesão e Prestação de Serviços.
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
