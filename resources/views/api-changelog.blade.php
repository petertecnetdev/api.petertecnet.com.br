@extends('layouts.api-public')

@section('title', 'Changelog')
@section('description', 'Histórico público de mudanças da Peter Tecnet Public API.')
@section('canonical', 'https://api.petertecnet.com.br/changelog')
@section('eyebrow', 'Release notes')
@section('heading', 'Changelog')
@section('lead', 'Mudanças do contrato público são registradas aqui para que integrações possam evoluir de forma previsível.')

@section('content')
<h2>3 de setembro de 2026 — Public API v1</h2>
<div class="grid">
    <div class="card"><strong>Contrato estável</strong><span>Nova superfície externa em /api/v1 e sandbox em /api/sandbox/v1, separadas das rotas internas dos aplicativos.</span></div>
    <div class="card"><strong>Developer Portal</strong><span>Criação de API Clients, ambientes, scopes, origens permitidas, chaves com rotação, métricas e logs.</span></div>
    <div class="card"><strong>OpenAPI 3.1</strong><span>Especificação processável em /openapi.json e referência humana em /docs.</span></div>
    <div class="card"><strong>Webhooks</strong><span>Endpoints HTTPS públicos, assinatura HMAC, fila, retry e histórico de entregas.</span></div>
    <div class="card"><strong>Observabilidade</strong><span>Request IDs, consumo por cliente, códigos 2xx/4xx/5xx e latências média, p50, p95 e p99.</span></div>
    <div class="card"><strong>Governança</strong><span>Rate limit por cliente, catálogo de erros, CORS por origem, versionamento e política de depreciação.</span></div>
</div>
<h2>Política de registro</h2>
<p>Correções internas que não alteram comportamento observável podem ser implantadas sem uma entrada individual. Novos campos compatíveis, novos endpoints, mudanças de limites, depreciações e alterações incompatíveis serão registrados.</p>
<div class="note">Para automação e geração de clientes, considere o OpenAPI como fonte de verdade técnica; este changelog explica a evolução do contrato.</div>
@endsection
