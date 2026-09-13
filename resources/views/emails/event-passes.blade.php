<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seus ingressos</title>
</head>
<body style="margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#172033;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6f8;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;">
<tr><td style="padding:28px 28px 14px;">
    <p style="margin:0 0 8px;font-size:13px;color:#667085;">{{ $applicationName }}</p>
    <h1 style="margin:0;font-size:24px;line-height:1.25;">Seus ingressos estão prontos</h1>
</td></tr>
<tr><td style="padding:0 28px 18px;">
    <p style="margin:0 0 8px;line-height:1.6;">Compra confirmada para <strong>{{ $order->event?->title ?? 'seu evento' }}</strong>.</p>
    <p style="margin:0;line-height:1.6;color:#475467;">{{ $passes->count() }} {{ $passes->count() === 1 ? 'ingresso foi emitido' : 'ingressos foram emitidos' }} para esta compra.</p>
</td></tr>
@if($passes->isNotEmpty())
<tr><td style="padding:0 28px 20px;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
    @foreach($passes as $pass)
        <tr>
            <td style="padding:12px 0;border-top:1px solid #eaecf0;">
                <strong>{{ $pass->holder_name ?: 'Participante' }}</strong>
                @if($pass->holder_email)
                    <div style="margin-top:4px;font-size:13px;color:#667085;">{{ $pass->holder_email }}</div>
                @endif
            </td>
        </tr>
    @endforeach
    </table>
</td></tr>
@endif
<tr><td style="padding:0 28px 28px;">
    <a href="{{ $frontendUrl }}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 20px;border-radius:10px;">Abrir {{ $applicationName }}</a>
    <p style="margin:18px 0 0;font-size:13px;line-height:1.5;color:#667085;">Apresente o ingresso disponível no aplicativo no acesso ao evento. Não compartilhe seu QR Code com terceiros.</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
