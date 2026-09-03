<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @page { margin: 24px 26px 34px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #13222b; font-size: 9px; line-height: 1.35; margin: 0; }
        .header { border-bottom: 2px solid #0b5363; padding-bottom: 12px; margin-bottom: 14px; }
        .brand { font-size: 10px; color: #0b5363; text-transform: uppercase; letter-spacing: 1.4px; font-weight: bold; }
        h1 { margin: 4px 0 2px; font-size: 22px; color: #07171d; }
        .description { color: #4a5c65; font-size: 9px; }
        .meta { margin-top: 7px; color: #667780; font-size: 8px; }
        .summary { width: 100%; border-collapse: separate; border-spacing: 6px; margin: 0 -6px 10px; }
        .summary td { border: 1px solid #d9e3e7; background: #f5f9fa; padding: 8px 9px; vertical-align: top; }
        .summary span { display: block; color: #60717a; font-size: 7px; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 3px; }
        .summary strong { font-size: 12px; color: #0b5363; }
        .filters { margin: 8px 0 12px; padding: 8px 10px; background: #f7f9fa; border: 1px solid #e1e8eb; }
        .filters b { color: #263b45; }
        .warning { margin: 8px 0 12px; padding: 8px 10px; background: #fff7e6; border: 1px solid #e8c777; color: #704f00; }
        table.data { width: 100%; border-collapse: collapse; table-layout: auto; }
        table.data thead { display: table-header-group; }
        table.data tr { page-break-inside: avoid; }
        table.data th { background: #0b5363; color: #fff; padding: 6px 5px; border: 1px solid #0b5363; font-size: 7px; text-align: left; }
        table.data td { border: 1px solid #dce4e7; padding: 5px; vertical-align: top; overflow-wrap: anywhere; }
        table.data tbody tr:nth-child(even) td { background: #f8fafb; }
        .empty { padding: 18px; text-align: center; color: #6d7c83; border: 1px solid #dce4e7; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; border-top: 1px solid #dce4e7; padding-top: 5px; font-size: 7px; color: #75858d; }
        .footer .right { float: right; }
    </style>
</head>
<body>
    <div class="footer">
        Peter Tecnet - Relatorio administrativo
        <span class="right">Gerado em {{ $report['generated_at'] }}</span>
    </div>

    <header class="header">
        <div class="brand">Peter Tecnet Admin Center</div>
        <h1>{{ $report['title'] }}</h1>
        <div class="description">{{ $report['description'] }}</div>
        <div class="meta">Periodo: {{ $report['period']['label'] }} | Gerado em: {{ $report['generated_at'] }}</div>
    </header>

    @if(!empty($report['summary']))
        <table class="summary">
            <tr>
                @foreach($report['summary'] as $label => $value)
                    <td>
                        <span>{{ $label }}</span>
                        <strong>{{ $value }}</strong>
                    </td>
                    @if($loop->iteration % 4 === 0 && !$loop->last)
                        </tr><tr>
                    @endif
                @endforeach
            </tr>
        </table>
    @endif

    @if(!empty($report['filters']))
        <div class="filters">
            <b>Filtros aplicados:</b>
            @foreach($report['filters'] as $label => $value)
                {{ $label }}: {{ $value }}@if(!$loop->last) | @endif
            @endforeach
        </div>
    @endif

    @if(!empty($report['truncated']))
        <div class="warning">
            O resultado excedeu {{ $report['max_rows'] }} linhas. O PDF apresenta as linhas mais recentes dentro do filtro selecionado para preservar desempenho e estabilidade.
        </div>
    @endif

    @if(!empty($report['rows']))
        <table class="data">
            <thead>
                <tr>
                    @foreach($report['columns'] as $column)
                        <th>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($report['rows'] as $row)
                    <tr>
                        @foreach($report['columns'] as $column)
                            <td>{{ data_get($row, $column['key'], '-') }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="empty">Nenhum registro encontrado para os filtros selecionados.</div>
    @endif
</body>
</html>
