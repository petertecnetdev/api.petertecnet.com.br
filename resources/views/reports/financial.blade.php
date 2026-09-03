<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Relatório financeiro Peter Tecnet</title>
<style>
body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#172033}h1{font-size:20px;margin:0 0 4px}h2{font-size:13px;margin:18px 0 7px}.muted{color:#667085}.kpis{width:100%;border-collapse:collapse;margin:12px 0}.kpis td{border:1px solid #d8dee9;padding:8px}.kpis b{display:block;font-size:14px;margin-top:3px}table.data{width:100%;border-collapse:collapse}table.data th,table.data td{border:1px solid #d8dee9;padding:5px;text-align:left;vertical-align:top}table.data th{background:#eef2f7}.right{text-align:right!important}.ok{color:#067647}.bad{color:#b42318}.footer{margin-top:16px;font-size:8px;color:#667085}
</style>
</head>
<body>
<h1>Relatório financeiro do ecossistema Peter Tecnet</h1>
<div class="muted">Período: {{ $from->format('d/m/Y H:i') }} até {{ $to->format('d/m/Y H:i') }} · Gerado em {{ $generatedAt->format('d/m/Y H:i:s') }}</div>
<table class="kpis"><tr>
<td>GMV confirmado<b>R$ {{ number_format($ledger['confirmed_gross'] ?? 0,2,',','.') }}</b></td>
<td>Estornos/chargebacks<b>R$ {{ number_format($ledger['reversed_gross'] ?? 0,2,',','.') }}</b></td>
<td>Saldo Peter Tecnet no período<b>R$ {{ number_format($ledger['platform_balance'] ?? 0,2,',','.') }}</b></td>
<td>Taxas de gateway<b>R$ {{ number_format($ledger['provider_fees'] ?? 0,2,',','.') }}</b></td>
<td>Saldo vendedor a repassar<b>R$ {{ number_format($ledger['seller_payable'] ?? 0,2,',','.') }}</b></td>
</tr></table>
<h2>Fechamento diário</h2>
<table class="data"><thead><tr><th>Data</th><th>Saldo inicial</th><th>Recebimentos</th><th>Estornos</th><th>Taxas gateway</th><th>Repasses</th><th>Ajustes</th><th>Saldo final</th><th>Receita Peter</th></tr></thead><tbody>
@foreach(($closing['days'] ?? []) as $day)
<tr><td>{{ $day['day'] }}</td><td class="right">R$ {{ number_format($day['opening_balance'],2,',','.') }}</td><td class="right">R$ {{ number_format($day['receipts'],2,',','.') }}</td><td class="right">R$ {{ number_format($day['reversals'],2,',','.') }}</td><td class="right">R$ {{ number_format($day['gateway_fees'],2,',','.') }}</td><td class="right">R$ {{ number_format($day['payouts'],2,',','.') }}</td><td class="right">R$ {{ number_format($day['adjustments'],2,',','.') }}</td><td class="right">R$ {{ number_format($day['closing_balance'],2,',','.') }}</td><td class="right">R$ {{ number_format($day['platform_revenue'],2,',','.') }}</td></tr>
@endforeach
</tbody></table>
<h2>Transações</h2>
<table class="data"><thead><tr><th>Data financeira</th><th>Aplicação</th><th>Provedor</th><th>Método</th><th>Status</th><th>Bruto</th><th>Taxa Peter</th><th>Taxa gateway</th><th>Líquido vendedor</th><th>ID provedor</th></tr></thead><tbody>
@foreach($rows as $row)
<tr><td>{{ $row['financial_at'] ?? '—' }}</td><td>{{ $row['app_slug'] ?? '—' }}</td><td>{{ $row['provider'] ?? '—' }}</td><td>{{ $row['method'] ?? '—' }}</td><td>{{ $row['status'] ?? '—' }}</td><td class="right">R$ {{ number_format((float)($row['gross_amount'] ?? 0),2,',','.') }}</td><td class="right">R$ {{ number_format((float)($row['platform_fee'] ?? 0),2,',','.') }}</td><td class="right">R$ {{ number_format((float)($row['provider_fee'] ?? 0),2,',','.') }}</td><td class="right">R$ {{ number_format((float)($row['seller_net'] ?? 0),2,',','.') }}</td><td>{{ $row['provider_payment_id'] ?? '—' }}</td></tr>
@endforeach
</tbody></table>
<div class="footer">Critério de reconhecimento: checkout, carrinho ou QR Code gerado não constitui receita. Receita é reconhecida somente após confirmação do pagamento pelo provedor. Estornos e chargebacks são registrados como eventos compensatórios e não apagam o lançamento original.</div>
</body>
</html>
