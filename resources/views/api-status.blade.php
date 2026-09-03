@extends('layouts.api-public')

@section('title', 'Status')
@section('description', 'Status operacional da Peter Tecnet Public API e seus componentes essenciais.')
@section('canonical', 'https://api.petertecnet.com.br/status')
@section('eyebrow', 'Disponibilidade')
@section('heading', 'Status da API')
@section('lead', 'Acompanhe a saúde da camada pública sem expor detalhes internos de infraestrutura.')

@section('content')
<div id="statusBadge" class="status"><span class="dot"></span><span>Consultando...</span></div>
<h2>Componentes</h2>
<div id="checks" class="grid"><div class="card"><strong>Carregando</strong><span>Aguardando resposta do endpoint de status.</span></div></div>
<h2>Endpoint para automação</h2>
<p>Use <code>GET /api/v1/status</code>. Ele não exige API Key e retorna HTTP 200 quando os componentes essenciais estão operacionais ou HTTP 503 em estado degradado.</p>
<div class="note">A página mostra apenas sinais de saúde necessários para integração. Exceções, hosts, credenciais e detalhes de rede não são publicados.</div>
@endsection

@push('scripts')
<script>
(async()=>{const badge=document.getElementById('statusBadge'),checks=document.getElementById('checks');try{const response=await fetch('/api/v1/status',{headers:{Accept:'application/json'}});const body=await response.json();const data=body.data||{};badge.querySelector('span:last-child').textContent=data.status==='operational'?'Todos os sistemas operacionais':'Serviço degradado';if(data.status!=='operational')badge.style.borderColor='#f7c96b';checks.innerHTML=Object.entries(data.checks||{}).map(([name,status])=>`<div class="card"><strong>${name}</strong><span>${status}</span></div>`).join('');}catch(e){badge.querySelector('span:last-child').textContent='Não foi possível consultar o status';checks.innerHTML='<div class="card"><strong>Indisponível</strong><span>Falha ao consultar o endpoint de saúde.</span></div>';}})();
</script>
@endpush
