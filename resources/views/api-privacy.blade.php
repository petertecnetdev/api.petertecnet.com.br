@extends('layouts.api-public')

@section('title', 'Privacidade da Developer Platform')
@section('description', 'Práticas de privacidade e minimização de dados da Peter Tecnet Developer Platform.')
@section('canonical', 'https://api.petertecnet.com.br/privacy')
@section('eyebrow', 'Privacidade')
@section('heading', 'Dados mínimos, finalidade clara')
@section('lead', 'A Developer Platform registra o necessário para autenticação, segurança, suporte, rate limiting e observabilidade da API pública.')

@section('content')
<h2>Dados do responsável</h2>
<p>O Developer Portal usa a conta Peter Tecnet autenticada para associar API Clients ao respectivo responsável. A Public API não publica dados privados da conta por meio das rotas externas de catálogo.</p>
<h2>Credenciais</h2>
<p>API Keys são exibidas integralmente somente na criação ou rotação. O servidor persiste o hash SHA-256 da chave, além de prefixo, validade, revogação e data aproximada de último uso. Signing secrets de webhook precisam ser recuperáveis para assinar entregas e, por isso, são armazenados usando criptografia da aplicação e nunca retornados depois da criação ou rotação.</p>
<h2>Logs de uso</h2>
<p>Para cada chamada autenticada da Public API podem ser registrados API Client, Request ID, método, caminho, status HTTP, duração, user agent truncado e um hash HMAC do IP. O corpo da requisição, API Key e cabeçalho Authorization não fazem parte do log específico da Developer Platform.</p>
<h2>Finalidades</h2>
<div class="grid">
    <div class="card"><strong>Segurança</strong><span>Detectar abuso, investigar incidentes e revogar credenciais comprometidas.</span></div>
    <div class="card"><strong>Operação</strong><span>Aplicar rate limits, acompanhar disponibilidade e diagnosticar erros.</span></div>
    <div class="card"><strong>Transparência</strong><span>Mostrar ao próprio desenvolvedor consumo, latência e histórico técnico de sua integração.</span></div>
    <div class="card"><strong>Evolução</strong><span>Entender quais contratos públicos são usados sem armazenar payloads sensíveis para analytics.</span></div>
</div>
<h2>Dados obtidos pela integração</h2>
<p>Desenvolvedores devem tratar os dados retornados pela API somente para a finalidade legítima da integração, aplicar minimização, controle de acesso e retenção apropriada e atender direitos dos titulares quando exigido pela legislação aplicável.</p>
<h2>Segurança e retenção</h2>
<p>Credenciais podem ser revogadas pelo portal. Logs técnicos devem ser mantidos pelo período necessário para segurança, suporte e obrigações operacionais, com revisão periódica da necessidade de retenção.</p>
<div class="note">A superfície pública usa projeções explícitas de dados. A existência de um campo em um model interno não significa que ele seja automaticamente disponibilizado para terceiros.</div>
@endsection
