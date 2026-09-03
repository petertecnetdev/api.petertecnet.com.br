@extends('layouts.api-public')

@section('title', 'Termos para Desenvolvedores')
@section('description', 'Termos de uso e política de uso aceitável da Peter Tecnet Public API.')
@section('canonical', 'https://api.petertecnet.com.br/terms')
@section('eyebrow', 'Governança')
@section('heading', 'Termos para desenvolvedores')
@section('lead', 'Condições essenciais para uso responsável da Peter Tecnet Public API. Vigência desta versão: 3 de setembro de 2026.')

@section('content')
<h2>1. Uso autorizado</h2>
<p>A Public API pode ser usada para criar integrações e experiências compatíveis com a documentação, os scopes concedidos e as leis aplicáveis. Uma credencial identifica uma integração específica e não deve ser revendida, compartilhada publicamente ou reutilizada para contornar limites.</p>
<h2>2. Segurança das credenciais</h2>
<p>O desenvolvedor é responsável por proteger API Keys e signing secrets, usar HTTPS e fazer rotação quando houver suspeita de exposição. Chaves live não devem ser incorporadas em código público ou distribuídas de forma que terceiros possam recuperá-las.</p>
<h2>3. Uso aceitável</h2>
<ul>
    <li>Não contornar autenticação, scopes, rate limits, controles de origem ou mecanismos antiabuso.</li>
    <li>Não realizar scraping agressivo, enumeração automatizada abusiva, exploração de vulnerabilidades ou degradação intencional do serviço.</li>
    <li>Não usar a API para fraude, spam, assédio, violação de privacidade, distribuição de malware ou atividade ilegal.</li>
    <li>Solicitar e armazenar apenas os dados necessários para a finalidade declarada da integração.</li>
</ul>
<h2>4. Limites e disponibilidade</h2>
<p>Limites podem variar por cliente, ambiente e risco operacional. A Peter Tecnet pode reduzir, suspender ou revogar acesso em caso de abuso, comprometimento de credencial, risco de segurança ou descumprimento destes termos.</p>
<h2>5. Evolução da API</h2>
<p>Contratos estáveis seguem a <a href="/deprecation">política de depreciação</a>. Mudanças emergenciais de segurança podem ser aplicadas mais rapidamente quando necessárias para proteger usuários, desenvolvedores ou a infraestrutura.</p>
<h2>6. Responsabilidade da integração</h2>
<p>O desenvolvedor deve validar respostas, tratar erros e timeouts, respeitar <code>Retry-After</code>, manter dependências seguras e garantir que sua própria aplicação cumpra requisitos legais e de privacidade aplicáveis.</p>
<div class="note">Ao criar uma aplicação no Developer Portal, o responsável confirma ciência destes termos e da política de privacidade da Developer Platform.</div>
@endsection
