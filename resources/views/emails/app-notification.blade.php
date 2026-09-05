<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $notification->title ?: 'Nova notificação Kryvion' }}</title>
</head>
<body style="margin:0;padding:0;background:#07111f;font-family:Arial,Helvetica,sans-serif;color:#eaf4ff;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#07111f;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#0c192b;border:1px solid #18324e;border-radius:18px;overflow:hidden;">
                <tr>
                    <td style="padding:28px 28px 12px;">
                        <div style="font-size:12px;letter-spacing:.14em;color:#61f4cb;font-weight:700;">KRYVION · NOVA NOTIFICAÇÃO</div>
                        <h1 style="margin:10px 0 12px;font-size:25px;line-height:1.25;color:#ffffff;">{{ $notification->title ?: 'Nova notificação' }}</h1>
                        @if($notification->message)
                            <p style="margin:0;font-size:16px;line-height:1.65;color:#c9d8e8;">{{ $notification->message }}</p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:12px 28px 28px;">
                        @php
                            $target = $notification->reference_url ?: rtrim((string) $application->url, '/');
                        @endphp
                        @if($target)
                            <a href="{{ $target }}" style="display:inline-block;background:#61f4cb;color:#06131f;text-decoration:none;font-weight:700;padding:13px 18px;border-radius:12px;">Ver detalhamento na Kryvion</a>
                        @endif
                        <p style="margin:22px 0 0;font-size:12px;line-height:1.55;color:#8195aa;">Você recebe este e-mail porque as notificações por e-mail da Kryvion estão ativadas na sua conta. Você pode desativá-las a qualquer momento na Central de Notificações da Kryvion. Análises de mercado não constituem recomendação financeira.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
