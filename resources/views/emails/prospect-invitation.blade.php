<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $application->name }}</title>
</head>
<body style="margin:0;padding:0;background:#070b14;font-family:Arial,Helvetica,sans-serif;color:#e8edf8;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#070b14;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#0e1524;border:1px solid #24314a;border-radius:22px;overflow:hidden;">
                <tr>
                    <td style="padding:34px 36px 18px;">
                        <div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#7f91b2;margin-bottom:10px;">Peter Tecnet · convite para {{ $personaLabel }}</div>
                        <h1 style="margin:0 0 14px;font-size:30px;line-height:1.15;color:#ffffff;">{{ $headline }}</h1>
                        @if($recipientName)
                            <p style="margin:0 0 10px;font-size:16px;color:#b9c6dc;">Olá, {{ $recipientName }}.</p>
                        @endif
                        <p style="margin:0;font-size:16px;line-height:1.65;color:#b9c6dc;">{{ $intro }}</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:10px 36px 8px;">
                        <div style="padding:18px 20px;border-radius:16px;background:#121d30;border:1px solid #253653;">
                            <div style="font-size:13px;color:#8ea2c3;margin-bottom:8px;">PLATAFORMA SELECIONADA</div>
                            <div style="font-size:22px;font-weight:700;color:#ffffff;">{{ $application->name }}</div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 36px 6px;">
                        <h2 style="font-size:18px;margin:0 0 12px;color:#ffffff;">O que você ganha com isso</h2>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                            @foreach($benefits as $benefit)
                                <tr>
                                    <td valign="top" style="width:22px;padding:7px 0;color:#7ca7ff;font-size:18px;">✓</td>
                                    <td style="padding:7px 0;font-size:15px;line-height:1.55;color:#c4d0e3;">{{ $benefit }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 36px 36px;">
                        <a href="{{ $applicationUrl }}" style="display:inline-block;padding:14px 22px;border-radius:12px;background:#ffffff;color:#0b1220;text-decoration:none;font-weight:700;font-size:15px;">{{ $ctaLabel }} →</a>
                        <p style="margin:20px 0 0;font-size:12px;line-height:1.55;color:#7183a2;">Este convite foi enviado pela Peter Tecnet para apresentar uma plataforma do nosso ecossistema. O botão acima leva diretamente para a aplicação selecionada.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
