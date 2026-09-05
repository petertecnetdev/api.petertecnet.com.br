<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#050507;font-family:Arial,Helvetica,sans-serif;color:#f5f1ff">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#050507;padding:24px 10px">
<tr><td align="center">
<table role="presentation" width="680" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:680px;background:#0b0910;border:1px solid #2b1e3f;border-radius:24px;overflow:hidden">
<tr><td style="padding:28px 32px;background:linear-gradient(135deg,#160d24,#09090d);border-bottom:1px solid #281d38">
<table role="presentation" width="100%"><tr><td width="58"><img src="https://kryvion.petertecnet.com.br/pwa-icon.svg" width="46" height="46" alt="Kryvion" style="display:block;border-radius:13px"></td><td><div style="font-size:11px;letter-spacing:3px;color:#a66cff;font-weight:700">KRYVION INTELLIGENCE</div><div style="font-size:23px;font-weight:800;margin-top:4px">Relatório de oportunidade</div></td></tr></table>
</td></tr>
<tr><td style="padding:28px 32px">
<div style="font-size:14px;color:#bdb4c7">Olá, {{ $recipientName ?: 'investidor' }}.</div>
<h1 style="font-size:30px;line-height:1.15;margin:10px 0 8px;color:#fff">{{ $report['symbol'] }} entrou no radar de alta</h1>
<p style="margin:0 0 22px;color:#948a9f;font-size:14px;line-height:1.7">A Kryvion detectou uma confluência relevante de momentum, aceleração, volume e liquidez. Este relatório resume o motivo do alerta e o cenário probabilístico atual.</p>

<table role="presentation" width="100%" cellspacing="8" cellpadding="0" border="0" style="margin:0 -8px 22px"><tr>
<td style="background:#121019;border:1px solid #2c2140;border-radius:14px;padding:15px"><div style="font-size:10px;color:#7e718d;letter-spacing:1px">SCORE</div><div style="font-size:28px;font-weight:800;color:#b87cff">{{ $report['score'] }}/100</div></td>
<td style="background:#121019;border:1px solid #2c2140;border-radius:14px;padding:15px"><div style="font-size:10px;color:#7e718d;letter-spacing:1px">CONFIANÇA</div><div style="font-size:28px;font-weight:800;color:#70ecc8">{{ $report['confidence'] }}%</div></td>
<td style="background:#121019;border:1px solid #2c2140;border-radius:14px;padding:15px"><div style="font-size:10px;color:#7e718d;letter-spacing:1px">JANELA</div><div style="font-size:17px;font-weight:800;color:#fff">{{ $report['entry_window'] }}</div></td>
</tr></table>

<div style="font-size:12px;font-weight:800;letter-spacing:1.5px;color:#c6a6ff;margin:22px 0 12px">MOVIMENTO OBSERVADO</div>
@foreach([['1H',$report['change_1h']],['24H',$report['change_24h']],['7D',$report['change_7d']]] as $metric)
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-bottom:9px"><tr><td width="44" style="font-size:11px;color:#867b91">{{ $metric[0] }}</td><td><div style="height:9px;background:#18131f;border-radius:20px;overflow:hidden"><div style="height:9px;width:{{ min(100,max(4,abs($metric[1])*2)) }}%;background:{{ $metric[1] >= 0 ? '#6ee7c5' : '#ff6d99' }};border-radius:20px"></div></div></td><td width="72" align="right" style="font-size:12px;font-weight:700;color:{{ $metric[1] >= 0 ? '#6ee7c5' : '#ff6d99' }}">{{ $metric[1] >= 0 ? '+' : '' }}{{ number_format($metric[1],2,',','.') }}%</td></tr></table>
@endforeach

<div style="font-size:12px;font-weight:800;letter-spacing:1.5px;color:#c6a6ff;margin:25px 0 10px">POR QUE O ALERTA FOI GERADO</div>
@foreach($report['reasons'] as $reason)
<div style="margin:0 0 8px;padding:11px 13px;background:#100e15;border-left:3px solid #8750e7;border-radius:8px;color:#c9c1d1;font-size:13px;line-height:1.5">{{ $reason }}</div>
@endforeach

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:22px;background:#0f1314;border:1px solid #1d4238;border-radius:16px"><tr><td style="padding:18px">
<div style="font-size:11px;color:#6ee7c5;font-weight:800;letter-spacing:1px">CENÁRIO ESTIMADO</div>
<div style="font-size:14px;color:#aaa1b3;line-height:1.7;margin-top:7px">Preço observado: <b style="color:#fff">US$ {{ number_format($report['price_usd'],6,',','.') }}</b><br>Faixa de alvo probabilística: <b style="color:#fff">US$ {{ number_format($report['target_price_min_usd'],6,',','.') }} – US$ {{ number_format($report['target_price_max_usd'],6,',','.') }}</b><br>Potencial estatístico calculado: <b style="color:#6ee7c5">+{{ number_format($report['upside_min_pct'],1,',','.') }}% a +{{ number_format($report['upside_max_pct'],1,',','.') }}%</b></div>
</td></tr></table>

<div style="text-align:center;margin:28px 0 12px"><a href="{{ $report['panel_url'] }}" style="display:inline-block;background:#7d3fe1;color:#fff;text-decoration:none;font-size:14px;font-weight:800;padding:15px 25px;border-radius:12px">Ver análise completa na Kryvion</a></div>
<p style="text-align:center;color:#706779;font-size:11px;line-height:1.6;margin:0">O painel autenticado mostra quando o sinal pode ser considerado confirmado, faixa de entrada, cenário de realização/saída, riscos e condições que invalidam a leitura.</p>

<div style="margin-top:26px;padding:14px 16px;border-radius:12px;background:#151016;border:1px solid #35202b;color:#a7959e;font-size:11px;line-height:1.6"><b style="color:#e8b8c9">Risco:</b> {{ implode(' ', $report['risks']) }} Esta é uma análise probabilística de mercado e não uma garantia de retorno ou recomendação individual de investimento.</div>
</td></tr>
<tr><td style="padding:18px 32px;background:#08070a;border-top:1px solid #211828;color:#665c70;font-size:10px;text-align:center">Kryvion · Peter Tecnet · Inteligência de mercado em tempo real</td></tr>
</table>
</td></tr></table>
</body></html>