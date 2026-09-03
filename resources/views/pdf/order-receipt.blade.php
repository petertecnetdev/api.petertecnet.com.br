<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#111}h1{font-size:20px}.meta{margin:14px 0;padding:10px;background:#f4f4f4}table{width:100%;border-collapse:collapse}th,td{padding:7px;border-bottom:1px solid #ddd;text-align:left}.right{text-align:right}</style></head>
<body>
<h1>Recibo de pedido</h1>
<div class="meta"><strong>Aplicação:</strong> {{ $application->name ?? 'Peter Tecnet' }}<br><strong>Número:</strong> {{ $receipt['number'] }}<br><strong>Status:</strong> {{ $receipt['status'] }}<br><strong>Data:</strong> {{ $receipt['created_at'] }}</div>
<p><strong>Cliente:</strong> {{ data_get($receipt,'buyer.name') }} — {{ data_get($receipt,'buyer.email') }}</p>
@if(data_get($receipt,'event.title'))<p><strong>Evento:</strong> {{ data_get($receipt,'event.title') }}</p>@endif
@if(data_get($receipt,'organization.name'))<p><strong>Organização:</strong> {{ data_get($receipt,'organization.name') }}</p>@endif
<table><thead><tr><th>Item</th><th>Qtd.</th><th class="right">Unitário</th><th class="right">Subtotal</th></tr></thead><tbody>
@foreach($receipt['items'] as $item)<tr><td>{{ $item['name'] }}</td><td>{{ $item['quantity'] }}</td><td class="right">R$ {{ number_format((float)$item['unit_price'],2,',','.') }}</td><td class="right">R$ {{ number_format((float)$item['subtotal'],2,',','.') }}</td></tr>@endforeach
</tbody></table>
<p class="right"><strong>Total: R$ {{ number_format((float)$receipt['total'],2,',','.') }}</strong></p>
<p>Documento gerado eletronicamente pela Peter Tecnet em {{ $receipt['generated_at'] }}.</p>
</body></html>
