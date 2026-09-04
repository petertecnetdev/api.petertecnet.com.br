<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ativação de acesso</title>
</head>
<body style="margin:0;background:#07111f;font-family:Arial,sans-serif;color:#eaf2ff;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#07111f;padding:28px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#0e1b2e;border:1px solid #223653;border-radius:18px;overflow:hidden;">
<tr><td style="padding:30px;">
    <div style="font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#7db9ff;">{{ $application->name }}</div>
    <h1 style="margin:10px 0 8px;font-size:26px;color:#ffffff;">Sua produção já está preparada</h1>
    <p style="margin:0 0 22px;line-height:1.6;color:#bfd0e8;">Olá, {{ $user->first_name }}. Seu acesso foi iniciado e a produção <strong style="color:#fff;">{{ $production->name }}</strong> já foi cadastrada para você.</p>

    @if($events->isNotEmpty())
        <div style="margin:20px 0;padding:18px;border-radius:14px;background:#0a1627;border:1px solid #21334e;">
            <div style="font-weight:700;margin-bottom:10px;">Eventos preparados</div>
            @foreach($events as $event)
                <div style="padding:9px 0;border-top:1px solid #1d304b;">
                    <strong>{{ $event->title }}</strong>
                    @if($event->start_date)
                        <div style="font-size:13px;color:#9eb3cf;margin-top:4px;">{{ $event->start_date->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <p style="margin:22px 0 8px;color:#bfd0e8;">Seu código de ativação é:</p>
    <div style="font-size:30px;font-weight:800;letter-spacing:.16em;color:#ffffff;background:#142844;border:1px solid #31547d;border-radius:12px;padding:16px;text-align:center;">{{ $code }}</div>
    <p style="font-size:13px;line-height:1.5;color:#91a7c4;">O código expira em 48 horas. Nunca compartilhe este código com terceiros.</p>

    <div style="text-align:center;margin:28px 0 18px;">
        <a href="{{ $activationUrl }}" style="display:inline-block;background:#4b9cff;color:#06111f;text-decoration:none;font-weight:800;padding:14px 24px;border-radius:10px;">{{ $requiresPassword ? 'Confirmar e criar minha senha' : 'Confirmar meu acesso' }}</a>
    </div>

    <p style="font-size:13px;line-height:1.6;color:#91a7c4;">Ao concluir a ativação, seu e-mail será confirmado. {{ $requiresPassword ? 'Você também definirá sua senha de acesso.' : 'Sua senha atual continuará válida, a menos que você escolha alterá-la.' }}</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
