<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0;background:#080719;font-family:Arial,Helvetica,sans-serif;color:#f7f5ff;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#080719;padding:28px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:linear-gradient(145deg,#17122f,#0e1730);border:1px solid #453a74;border-radius:22px;overflow:hidden;">
            <tr><td style="padding:34px;">
                <div style="font-size:12px;letter-spacing:1.8px;text-transform:uppercase;color:#d985ff;font-weight:700;">Atualização de convite artístico</div>
                <h1 style="margin:12px 0 12px;color:#fff;font-size:27px;line-height:1.25;">{{ $heading }}</h1>
                <p style="margin:0;color:#c9c5dc;font-size:16px;line-height:1.65;">{{ $messageText }}</p>
                <div style="margin-top:22px;padding:17px 19px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10);border-radius:15px;color:#d8d4e8;line-height:1.7;font-size:14px;">
                    <div><strong style="color:#fff;">Evento:</strong> {{ $eventTitle }}</div>
                    @if($producerName)<div><strong style="color:#fff;">Produção:</strong> {{ $producerName }}</div>@endif
                    @if($eventDate)<div><strong style="color:#fff;">Data:</strong> {{ $eventDate }}</div>@endif
                </div>
                <p style="margin:20px 0 0;color:#8f8aa8;font-size:12px;line-height:1.6;">Esta mensagem é transacional e se refere a um convite artístico enviado anteriormente para este endereço.</p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
