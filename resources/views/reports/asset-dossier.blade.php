<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Dossiê patrimonial</title>
<style>
    @page { margin: 28px 34px; }
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 10px; line-height: 1.45; }
    h1,h2,h3,p { margin: 0; }
    h1 { font-size: 23px; margin-top: 5px; }
    h2 { font-size: 15px; margin-bottom: 8px; color: #12213a; }
    h3 { font-size: 11px; margin-bottom: 5px; }
    .muted { color: #6c778b; }
    .eyebrow { color: #3767d6; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; }
    .header { padding-bottom: 16px; border-bottom: 2px solid #dfe6f2; margin-bottom: 16px; }
    .header-grid { width: 100%; }
    .header-grid td { vertical-align: top; }
    .right { text-align: right; }
    .badge { display: inline-block; padding: 4px 7px; border: 1px solid #dbe3f0; border-radius: 10px; font-size: 8px; margin-left: 3px; }
    .section { margin-bottom: 16px; }
    .grid { width: 100%; border-collapse: separate; border-spacing: 6px; margin-left: -6px; margin-right: -6px; }
    .metric { border: 1px solid #e1e7f0; border-radius: 7px; padding: 9px; vertical-align: top; width: 25%; }
    .metric small { color: #748096; display: block; margin-bottom: 3px; }
    .metric strong { font-size: 13px; color: #0f1b2d; }
    .card { border: 1px solid #e1e7f0; border-radius: 7px; padding: 10px; margin-bottom: 8px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { text-align: left; font-size: 8px; color: #69758a; text-transform: uppercase; background: #f5f7fb; padding: 6px; border-bottom: 1px solid #e1e7f0; }
    table.data td { padding: 6px; border-bottom: 1px solid #edf0f5; vertical-align: top; }
    .money { text-align: right; white-space: nowrap; }
    .alert { border-left: 3px solid #8b95a6; padding: 6px 8px; margin-bottom: 5px; background: #f7f8fb; }
    .alert.critical { border-left-color: #b42318; }
    .alert.attention { border-left-color: #b7791f; }
    .alert.upcoming { border-left-color: #2463eb; }
    .score { font-size: 26px; font-weight: 700; }
    .positive { color: #18794e; }
    .negative { color: #b42318; }
    .page-break { page-break-before: always; }
    .avoid-break { page-break-inside: avoid; }
    .footer-note { color: #8a94a6; font-size: 8px; margin-top: 20px; border-top: 1px solid #e5e9f0; padding-top: 8px; }
</style>
</head>
<body>
@php
    $money = fn($v) => 'R$ '.number_format((float)($v ?? 0), 2, ',', '.');
    $date = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '—';
    $dateTime = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y H:i') : '—';
    $health = $analytics['health'] ?? ['score' => null, 'grade' => null, 'factors' => [], 'positive_factors' => []];
@endphp

<div class="header">
    <table class="header-grid"><tr>
        <td>
            <div class="eyebrow">Dossiê patrimonial · Peter Tecnet</div>
            <h1>{{ $asset->name ?? ucfirst($assetType).' #'.$asset->id }}</h1>
            @if($assetType === 'property')
                <p class="muted">{{ $asset->street }}{{ $asset->number ? ', '.$asset->number : '' }} · {{ $asset->neighborhood }} · {{ $asset->city }}/{{ $asset->state }}</p>
            @endif
        </td>
        <td class="right">
            <span class="badge">{{ strtoupper($asset->status ?? 'ativo') }}</span>
            <p class="muted" style="margin-top:8px">Gerado em {{ $dateTime($generatedAt) }}</p>
            <p class="muted">Por {{ trim(($generatedBy->first_name ?? '').' '.($generatedBy->last_name ?? '')) ?: ($generatedBy->email ?? 'Usuário') }}</p>
        </td>
    </tr></table>
</div>

<div class="section">
    <h2>Resumo executivo</h2>
    <table class="grid"><tr>
        <td class="metric"><small>Saúde do patrimônio</small><strong class="score">{{ $health['score'] ?? '—' }}</strong><p class="muted">{{ $health['grade'] ?? 'sem classificação' }}</p></td>
        <td class="metric"><small>Valor de mercado</small><strong>{{ $profile['market_value'] ? $money($profile['market_value']) : 'Não informado' }}</strong></td>
        <td class="metric"><small>Valor de aquisição</small><strong>{{ $profile['acquisition_value'] ? $money($profile['acquisition_value']) : 'Não informado' }}</strong></td>
        <td class="metric"><small>Alertas ativos</small><strong>{{ count($alerts) }}</strong><p class="muted">{{ collect($alerts)->where('severity','critical')->count() }} crítico(s)</p></td>
    </tr></table>

    @if($financial)
    <table class="grid"><tr>
        <td class="metric"><small>Receita bruta 12m</small><strong>{{ $money($financial['summary']['gross_income_12m'] ?? 0) }}</strong></td>
        <td class="metric"><small>Despesas 12m</small><strong>{{ $money(($financial['summary']['owner_expenses_12m'] ?? 0) + ($financial['summary']['maintenance_cost_12m'] ?? 0)) }}</strong></td>
        <td class="metric"><small>Resultado líquido 12m</small><strong>{{ $money($financial['summary']['net_result_12m'] ?? 0) }}</strong></td>
        <td class="metric"><small>Rentabilidade líquida</small><strong>{{ ($financial['summary']['net_yield_12m'] ?? null) !== null ? number_format($financial['summary']['net_yield_12m'],2,',','.').'%' : '—' }}</strong></td>
    </tr></table>
    @endif
</div>

@if($assetType === 'property')
<div class="section avoid-break">
    <h2>Características do imóvel</h2>
    <table class="data">
        <tr><th>Tipo</th><th>Uso</th><th>Área</th><th>Quartos</th><th>Banheiros</th><th>Vagas</th></tr>
        <tr><td>{{ $asset->type }}</td><td>{{ $asset->use_type }}</td><td>{{ $asset->area_m2 ? number_format($asset->area_m2,2,',','.').' m²' : '—' }}</td><td>{{ $asset->bedrooms ?? '—' }}</td><td>{{ $asset->bathrooms ?? '—' }}</td><td>{{ $asset->parking_spaces ?? '—' }}</td></tr>
    </table>
    @if($tags)<p style="margin-top:8px"><strong>Tags:</strong> {{ implode(' · ', $tags) }}</p>@endif
    <p style="margin-top:6px"><strong>Registro:</strong> {{ $profile['registry_reference'] ?: 'não informado' }} &nbsp; <strong>Seguro até:</strong> {{ $date($profile['insurance_expires_on']) }} &nbsp; <strong>Documento até:</strong> {{ $date($profile['document_expires_on']) }}</p>
</div>
@endif

<div class="section">
    <h2>Alertas e pontos de atenção</h2>
    @forelse($alerts as $alert)
        <div class="alert {{ $alert['severity'] ?? '' }}"><strong>{{ $alert['title'] }}</strong><br>{{ $alert['message'] }} @if(!empty($alert['dueAt']))<span class="muted"> · {{ $date($alert['dueAt']) }}</span>@endif</div>
    @empty
        <p class="positive">Nenhum alerta operacional ativo.</p>
    @endforelse
</div>

<div class="section">
    <h2>Composição de propriedade e acessos permanentes</h2>
    <table class="data"><tr><th>Pessoa</th><th>E-mail</th><th>Papel</th><th>Participação</th></tr>
    @forelse($ownerships as $owner)
        <tr><td>{{ trim($owner->first_name.' '.($owner->last_name ?? '')) }}</td><td>{{ $owner->email }}</td><td>{{ $owner->role }}</td><td>{{ number_format($owner->share_percent,2,',','.') }}%</td></tr>
    @empty<tr><td colspan="4">Nenhuma composição adicional cadastrada.</td></tr>@endforelse
    </table>
</div>

@if($financial)
<div class="page-break"></div>
<div class="section">
    <h2>Financeiro do patrimônio</h2>
    <table class="grid"><tr>
        <td class="metric"><small>Pendente</small><strong>{{ $money($financial['summary']['pending'] ?? 0) }}</strong></td>
        <td class="metric"><small>Em atraso</small><strong>{{ $money($financial['summary']['overdue'] ?? 0) }}</strong></td>
        <td class="metric"><small>Inadimplência</small><strong>{{ number_format($financial['summary']['delinquency_rate'] ?? 0,2,',','.') }}%</strong></td>
        <td class="metric"><small>Manutenção 12m</small><strong>{{ $money($financial['summary']['maintenance_cost_12m'] ?? 0) }}</strong></td>
    </tr></table>
    <h3>Lançamentos patrimoniais</h3>
    <table class="data"><tr><th>Data</th><th>Tipo</th><th>Categoria</th><th>Descrição</th><th class="money">Valor</th><th>Status</th></tr>
    @forelse($financial['entries'] ?? [] as $entry)
        <tr><td>{{ $date($entry['occurred_on']) }}</td><td>{{ $entry['direction'] }}</td><td>{{ $entry['category'] }}</td><td>{{ $entry['description'] }}</td><td class="money">{{ $money($entry['amount']) }}</td><td>{{ $entry['status'] }}</td></tr>
    @empty<tr><td colspan="6">Nenhum lançamento patrimonial independente.</td></tr>@endforelse
    </table>
</div>
@endif

@if($assetType === 'property')
<div class="section">
    <h2>Histórico de locações</h2>
    <table class="data"><tr><th>Inquilino</th><th>Início</th><th>Fim</th><th>Aluguel</th><th>Status</th></tr>
    @forelse($leases as $lease)
        <tr><td>{{ $lease->tenant_name }}</td><td>{{ $date($lease->starts_on) }}</td><td>{{ $date($lease->ends_on) }}</td><td>{{ $money($lease->rent_amount) }}</td><td>{{ $lease->status }}</td></tr>
    @empty<tr><td colspan="5">Nenhuma locação registrada.</td></tr>@endforelse
    </table>
</div>
@endif

<div class="page-break"></div>
<div class="section">
    <h2>Ambientes e inventário</h2>
    @forelse($spaces as $space)
        <div class="card avoid-break"><h3>{{ $space->name }} <span class="muted">· {{ $space->type }}</span></h3>
            @php $roomItems = $inventory->where('space_id', $space->id); @endphp
            @if($roomItems->count())
                <table class="data"><tr><th>Item</th><th>Categoria</th><th>Estado</th><th>Qtd.</th><th>Serial</th></tr>
                @foreach($roomItems as $item)<tr><td>{{ $item->name }}</td><td>{{ $item->category }}</td><td>{{ $item->condition }}</td><td>{{ $item->quantity }}</td><td>{{ $item->serial_number ?: '—' }}</td></tr>@endforeach
                </table>
            @else<p class="muted">Sem itens cadastrados neste ambiente.</p>@endif
        </div>
    @empty
        <p class="muted">Nenhum ambiente estruturado.</p>
    @endforelse
    @php $unassigned = $inventory->whereNull('space_id'); @endphp
    @if($unassigned->count())
        <div class="card"><h3>Itens sem ambiente</h3>@foreach($unassigned as $item)<p>{{ $item->name }} · {{ $item->condition }} · {{ $item->quantity }} un.</p>@endforeach</div>
    @endif
</div>

<div class="section">
    <h2>Manutenção preventiva</h2>
    <table class="data"><tr><th>Plano</th><th>Ambiente</th><th>Frequência</th><th>Próxima</th><th>Prioridade</th><th>Estimativa</th></tr>
    @forelse($preventive as $plan)
        <tr><td>{{ $plan->title }}</td><td>{{ $plan->space_name ?: 'Geral' }}</td><td>{{ $plan->frequency_months }} meses</td><td>{{ $date($plan->next_due_on) }}</td><td>{{ $plan->priority }}</td><td>{{ $plan->estimated_cost !== null ? $money($plan->estimated_cost) : '—' }}</td></tr>
    @empty<tr><td colspan="6">Nenhuma rotina preventiva cadastrada.</td></tr>@endforelse
    </table>
</div>

@if($assetType === 'property')
<div class="section">
    <h2>Manutenções corretivas e operacionais</h2>
    <table class="data"><tr><th>Ocorrência</th><th>Status</th><th>Prioridade</th><th>Prazo</th><th>Custo</th></tr>
    @forelse($maintenance as $item)
        <tr><td>{{ $item['title'] }}</td><td>{{ $item['status'] }}</td><td>{{ $item['priority'] }}</td><td>{{ $date($item['due_at']) }}</td><td>{{ $item['amount'] !== null ? $money($item['amount']) : '—' }}</td></tr>
    @empty<tr><td colspan="5">Nenhuma manutenção registrada.</td></tr>@endforelse
    </table>
</div>

<div class="section">
    <h2>Vistorias</h2>
    <table class="data"><tr><th>Data</th><th>Tipo</th><th>Resumo</th><th>Itens</th></tr>
    @forelse($inspections as $inspection)
        <tr><td>{{ $dateTime($inspection['occurred_at']) }}</td><td>{{ $inspection['type'] }}</td><td>{{ $inspection['summary'] ?: '—' }}</td><td>{{ count($inspection['items'] ?? []) }}</td></tr>
    @empty<tr><td colspan="4">Nenhuma vistoria registrada.</td></tr>@endforelse
    </table>
</div>
@endif

<div class="section">
    <h2>Acervo documental</h2>
    <table class="data"><tr><th>Arquivo</th><th>Grupo</th><th>Contexto</th><th>Data</th></tr>
    @forelse($files as $file)
        <tr><td>{{ $file['original_name'] }}</td><td>{{ $file['group'] }}</td><td>{{ $file['meta']['context'] ?? 'geral' }}</td><td>{{ $date($file['meta']['occurred_on'] ?? $file['created_at']) }}</td></tr>
    @empty<tr><td colspan="4">Nenhum arquivo permanente cadastrado.</td></tr>@endforelse
    </table>
</div>

@if($audit->count())
<div class="page-break"></div>
<div class="section">
    <h2>Auditoria patrimonial</h2>
    <table class="data"><tr><th>Quando</th><th>Quem</th><th>Evento</th><th>Descrição</th></tr>
    @foreach($audit as $event)
        <tr><td>{{ $dateTime($event['occurred_at']) }}</td><td>{{ $event['actor_name'] }}</td><td>{{ $event['title'] }}</td><td>{{ $event['description'] ?: '—' }}</td></tr>
    @endforeach
    </table>
</div>
@endif

<div class="footer-note">Este documento consolida informações registradas no domínio genérico de patrimônio da Peter Tecnet. Valores, documentos e eventos refletem o estado da base no momento da geração.</div>
</body>
</html>
