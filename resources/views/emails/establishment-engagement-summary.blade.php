<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $report['subject'] }}</title>
</head>
<body style="margin:0;background:#07111f;font-family:Arial,Helvetica,sans-serif;color:#eaf2ff;">
@php
    $metrics = $report['metrics'] ?? [];
    $money = static fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
@endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#07111f;padding:28px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#0d1b2a;border:1px solid #23364a;border-radius:22px;overflow:hidden;">
            <tr><td style="padding:30px 32px 20px;background:linear-gradient(135deg,#0d1b2a,#132b42);">
                <div style="font-size:12px;letter-spacing:.16em;text-transform:uppercase;color:#8fb3d9;font-weight:700;">{{ $application->name ?: 'Peter Tecnet' }}</div>
                <h1 style="margin:10px 0 8px;font-size:29px;line-height:1.2;color:#fff;">{{ $report['headline'] }}</h1>
                <p style="margin:0;font-size:16px;line-height:1.65;color:#cddbea;">Olá, {{ $recipientName ?: 'produtor' }}. {{ $report['intro'] }}</p>
            </td></tr>

            <tr><td style="padding:22px 32px 6px;">
                <div style="font-size:13px;color:#8fb3d9;text-transform:uppercase;letter-spacing:.12em;font-weight:700;margin-bottom:12px;">Resumo da sua produção</div>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                    <tr>
                        <td width="50%" style="padding:0 6px 12px 0;"><div style="border:1px solid #263b50;border-radius:14px;padding:16px;"><div style="font-size:25px;font-weight:800;color:#fff;">{{ $metrics['events_future'] ?? 0 }}</div><div style="font-size:13px;color:#9fb2c5;">próximos eventos</div></div></td>
                        <td width="50%" style="padding:0 0 12px 6px;"><div style="border:1px solid #263b50;border-radius:14px;padding:16px;"><div style="font-size:25px;font-weight:800;color:#fff;">{{ $metrics['tickets_sold'] ?? 0 }}</div><div style="font-size:13px;color:#9fb2c5;">ingressos vendidos</div></div></td>
                    </tr>
                    <tr>
                        <td width="50%" style="padding:0 6px 12px 0;"><div style="border:1px solid #263b50;border-radius:14px;padding:16px;"><div style="font-size:25px;font-weight:800;color:#fff;">{{ $money($metrics['revenue'] ?? 0) }}</div><div style="font-size:13px;color:#9fb2c5;">vendas pagas</div></div></td>
                        <td width="50%" style="padding:0 0 12px 6px;"><div style="border:1px solid #263b50;border-radius:14px;padding:16px;"><div style="font-size:25px;font-weight:800;color:#fff;">{{ $metrics['event_views'] ?? 0 }}</div><div style="font-size:13px;color:#9fb2c5;">visualizações</div></div></td>
                    </tr>
                </table>
            </td></tr>

            @if(!empty($report['top_event']))
                <tr><td style="padding:8px 32px 4px;">
                    <div style="border-radius:16px;background:#10263a;border:1px solid #29445e;padding:18px;">
                        <div style="font-size:12px;text-transform:uppercase;letter-spacing:.11em;color:#8fb3d9;font-weight:700;">Destaque atual</div>
                        <div style="font-size:19px;font-weight:800;color:#fff;margin-top:6px;">{{ $report['top_event']['title'] }}</div>
                        @if(isset($report['top_event']['issued']))<div style="font-size:14px;color:#b6c7d8;margin-top:5px;">{{ $report['top_event']['issued'] }} ingresso(s) emitido(s)</div>@endif
                    </div>
                </td></tr>
            @endif

            <tr><td style="padding:18px 32px 4px;">
                <div style="font-size:13px;color:#8fb3d9;text-transform:uppercase;letter-spacing:.12em;font-weight:700;margin-bottom:12px;">O que pode melhorar</div>
                @foreach(($report['insights'] ?? []) as $insight)
                    <div style="margin-bottom:10px;border:1px solid #263b50;border-radius:14px;padding:15px 16px;background:#0a1724;">
                        <div style="font-size:15px;color:#fff;font-weight:800;margin-bottom:5px;">{{ $insight['title'] }}</div>
                        <div style="font-size:14px;line-height:1.55;color:#b9c9d8;">{{ $insight['message'] }}</div>
                    </div>
                @endforeach
            </td></tr>

            <tr><td style="padding:18px 32px 30px;">
                <div style="font-size:13px;color:#8fb3d9;text-transform:uppercase;letter-spacing:.12em;font-weight:700;margin-bottom:13px;">Acesse e continue movimentando sua produção</div>
                @foreach($ctas as $cta)
                    <a href="{{ $cta['url'] }}" style="display:inline-block;margin:0 8px 10px 0;padding:13px 18px;border-radius:12px;{{ !empty($cta['primary']) ? 'background:#ffffff;color:#07111f;' : 'background:#14283b;color:#ffffff;border:1px solid #34506b;' }}text-decoration:none;font-size:14px;font-weight:800;">{{ $cta['label'] }}</a>
                @endforeach
                <p style="margin:14px 0 0;font-size:13px;line-height:1.55;color:#8fa5bb;">Quanto mais completa e atualizada estiver sua produção, mais fácil fica para o público descobrir eventos, acompanhar sua agenda e comprar diretamente pela plataforma.</p>
            </td></tr>

            <tr><td style="padding:18px 32px 26px;border-top:1px solid #23364a;font-size:12px;line-height:1.55;color:#8fa5bb;">
                Resumo gerado automaticamente a partir dos dados reais da sua produção. Comunicação {{ $communicationId }} · Peter Tecnet.
            </td></tr>
        </table>
    </td></tr>
</table>
@if($trackingPixelUrl)
    <img src="{{ $trackingPixelUrl }}" alt="" width="1" height="1" style="display:block;width:1px;height:1px;border:0;" />
@endif
</body>
</html>
