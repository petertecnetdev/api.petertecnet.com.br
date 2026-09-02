<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Seus ingressos | Cutinapp</title>
</head>
<body style="margin:0;background:#07111f;color:#f7f8ff;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#07111f;padding:28px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#0c1727;border:1px solid #22324a;border-radius:20px;overflow:hidden;">
            <tr><td style="padding:30px 30px 18px;">
                <div style="font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#56dce9;font-weight:700;">Cutinapp</div>
                <h1 style="margin:10px 0 8px;font-size:30px;line-height:1.1;color:#ffffff;">Seus ingressos estão prontos 🎟️</h1>
                <p style="margin:0;color:#b6c2d4;line-height:1.6;">Sua compra para <strong style="color:#fff;">{{ $order->event?->title }}</strong> foi confirmada. Sua presença já está marcada e você receberá as novidades do evento pela Cutinapp.</p>
            </td></tr>
            <tr><td style="padding:0 30px 24px;">
                @foreach($passes as $pass)
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:12px;background:#101f33;border:1px solid #263a55;border-radius:14px;">
                        <tr><td style="padding:18px;">
                            <div style="font-size:12px;color:#56dce9;text-transform:uppercase;letter-spacing:.08em;">Ingresso #{{ $pass->id }}</div>
                            <div style="font-size:18px;font-weight:700;color:#fff;margin-top:5px;">{{ $pass->ticket?->name ?? 'Ingresso' }}</div>
                            <div style="font-size:14px;color:#b6c2d4;margin-top:6px;">Titular: {{ $pass->holder_name ?: ($order->user?->first_name ?? 'Participante') }}</div>
                            <div style="margin-top:14px;">
                                <a href="{{ $frontendUrl }}/passes/{{ $pass->id }}" style="display:inline-block;background:#56dce9;color:#07111f;text-decoration:none;font-weight:800;padding:11px 16px;border-radius:10px;">Abrir ingresso e QR Code</a>
                            </div>
                        </td></tr>
                    </table>
                @endforeach
            </td></tr>
            <tr><td style="padding:0 30px 30px;">
                <a href="{{ $frontendUrl }}/passes" style="display:inline-block;background:#7d5cff;color:#fff;text-decoration:none;font-weight:800;padding:13px 18px;border-radius:11px;">Ver todos os meus ingressos</a>
                <a href="{{ $frontendUrl }}/event/{{ $order->event?->slug }}" style="display:inline-block;margin-left:8px;color:#56dce9;text-decoration:none;font-weight:700;padding:13px 4px;">Ver evento</a>
                <p style="margin:22px 0 0;color:#7f8da3;font-size:12px;line-height:1.5;">Guarde este e-mail. Você também pode acessar seus ingressos a qualquer momento entrando na sua conta da Cutinapp.</p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
