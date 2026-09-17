<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Convite para {{ $eventTitle }}</title>
</head>
<body style="margin:0;background:#080719;font-family:Arial,Helvetica,sans-serif;color:#f7f5ff;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#080719;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:linear-gradient(145deg,#17122f,#0e1730);border:1px solid #453a74;border-radius:22px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.35);">
                <tr>
                    <td style="padding:34px 34px 18px;">
                        <div style="font-size:12px;letter-spacing:1.8px;text-transform:uppercase;color:#d985ff;font-weight:700;">Cutinapp · convite artístico</div>
                        <h1 style="margin:12px 0 10px;font-size:28px;line-height:1.2;color:#fff;">Você foi convidado para um evento</h1>
                        <p style="margin:0;color:#c9c5dc;font-size:16px;line-height:1.65;">
                            {{ $producerName }} convidou você para participar de <strong style="color:#fff;">{{ $eventTitle }}</strong>.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 34px 18px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10);border-radius:16px;">
                            <tr>
                                <td style="padding:18px 20px;color:#d8d4e8;font-size:14px;line-height:1.7;">
                                    @if($participationType)<div><strong style="color:#fff;">Participação:</strong> {{ $participationType }}</div>@endif
                                    @if($stage)<div><strong style="color:#fff;">Palco / espaço:</strong> {{ $stage }}</div>@endif
                                    @if($scheduledAt)<div><strong style="color:#fff;">Horário previsto:</strong> {{ $scheduledAt }}</div>@endif
                                    <div style="margin-top:8px;">Crie sua conta usando este mesmo e-mail. Depois da confirmação do e-mail, o convite ficará vinculado automaticamente ao seu perfil artístico.</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:4px 34px 34px;">
                        <a href="{{ $registrationUrl }}" style="display:inline-block;padding:14px 24px;border-radius:12px;background:linear-gradient(90deg,#c33cff,#1b9cff);color:#fff;text-decoration:none;font-weight:700;font-size:15px;">Criar conta na Cutinapp</a>
                        <p style="margin:20px 0 0;color:#8f8aa8;font-size:12px;line-height:1.6;">Se você já criou sua conta com este e-mail, basta entrar na Cutinapp. O vínculo pendente será recuperado automaticamente.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
