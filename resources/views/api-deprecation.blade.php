@extends('layouts.api-public')

@section('title', 'Política de Depreciação')
@section('description', 'Política de versionamento, depreciação e sunset da Peter Tecnet Public API.')
@section('canonical', 'https://api.petertecnet.com.br/deprecation')
@section('eyebrow', 'Lifecycle')
@section('heading', 'Depreciação previsível')
@section('lead', 'Integrações externas precisam de tempo para migrar. A v1 estabelece uma política explícita para mudanças incompatíveis.')

@section('content')
<h2>Compatibilidade</h2>
<p>Dentro de uma versão estável, a Peter Tecnet pode adicionar campos opcionais, novos endpoints e novos valores documentados sem considerar isso uma quebra. Consumidores devem ignorar campos desconhecidos.</p>
<h2>Mudanças incompatíveis</h2>
<p>Remoção ou renomeação de campos, mudança de tipo, alteração de semântica incompatível, remoção de endpoint ou mudança obrigatória de autenticação exige nova versão ou um ciclo formal de depreciação.</p>
<div class="grid">
    <div class="card"><strong>Aviso padrão</strong><span>Mínimo de 90 dias antes do sunset de um contrato estável incompatível.</span></div>
    <div class="card"><strong>Comunicação</strong><span>Changelog, documentação e headers de lifecycle passam a indicar a depreciação.</span></div>
    <div class="card"><strong>Headers</strong><span>X-API-Deprecated informa o estado; versões em sunset poderão adicionar Deprecation, Sunset e Link.</span></div>
    <div class="card"><strong>Exceção de segurança</strong><span>Falhas críticas, abuso ou obrigação legal podem exigir ação mais rápida para proteger usuários e a plataforma.</span></div>
</div>
<h2>Versionamento</h2>
<p>A versão está no caminho, por exemplo <code>/api/v1</code>. Quando uma v2 for necessária, v1 e v2 poderão coexistir durante o período de migração.</p>
<div class="note">As rotas internas em <code>/api/v1/apps/{application}</code> pertencem ao contrato do ecossistema Peter Tecnet e não devem ser tratadas como compromisso público para terceiros.</div>
@endsection
