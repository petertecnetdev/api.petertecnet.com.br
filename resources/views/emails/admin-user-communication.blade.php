<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $mailSubject }}</title>
</head>
<body style="margin:0;background:#07111f;font-family:Arial,Helvetica,sans-serif;color:#eaf2ff;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#07111f;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#0d1b2a;border:1px solid #23364a;border-radius:20px;overflow:hidden;">
                <tr>
                    <td style="padding:28px 32px 18px;">
                        <div style="font-size:12px;letter-spacing:.16em;text-transform:uppercase;color:#8fb3d9;font-weight:700;">Peter Tecnet</div>
                        <h1 style="margin:10px 0 0;font-size:26px;line-height:1.25;color:#ffffff;">{{ $mailSubject }}</h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 28px;">
                        <p style="margin:0 0 18px;font-size:16px;line-height:1.65;color:#dce8f5;">Olá, {{ $recipient->first_name ?: $recipient->user_name ?: 'usuário' }}.</p>
                        <div style="font-size:16px;line-height:1.7;color:#dce8f5;white-space:normal;">{!! nl2br(e($body)) !!}</div>
                        @if ($actionUrl)
                            <div style="margin-top:26px;">
                                <a href="{{ $actionUrl }}" style="display:inline-block;padding:13px 20px;border-radius:12px;background:#ffffff;color:#07111f;text-decoration:none;font-weight:700;">Abrir informação</a>
                            </div>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 32px 26px;border-top:1px solid #23364a;font-size:12px;line-height:1.55;color:#8fa5bb;">
                        Esta mensagem foi enviada pela equipe Peter Tecnet por meio do Admin Center. Não compartilhe senhas, códigos de verificação ou dados financeiros em resposta a este e-mail.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
