<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $notification->title }}</title>
</head>
@php
    $notificationData = is_array($notification->data) ? $notification->data : [];
    $actionLabel = trim((string) ($notificationData['action_label'] ?? ''));
@endphp
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6f8;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e5e7eb;">
                <tr>
                    <td style="padding:28px 28px 18px;background:#111827;color:#ffffff;">
                        <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;opacity:.75;">{{ $application->name ?: 'Cutinapp' }}</div>
                        <h1 style="margin:10px 0 0;font-size:24px;line-height:1.25;">{{ $notification->title ?: 'Nova notificação' }}</h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px;">
                        <p style="margin:0 0 14px;font-size:16px;line-height:1.6;">Olá, {{ $recipient->name ?: 'usuário' }}.</p>
                        @if($notification->message)
                            <p style="margin:0 0 24px;font-size:16px;line-height:1.65;white-space:pre-line;">{{ $notification->message }}</p>
                        @else
                            <p style="margin:0 0 24px;font-size:16px;line-height:1.65;">Há uma nova movimentação que precisa da sua atenção.</p>
                        @endif

                        <table role="presentation" cellspacing="0" cellpadding="0">
                            <tr>
                                <td style="border-radius:12px;background:#111827;">
                                    <a href="{{ $actionUrl }}" style="display:inline-block;padding:14px 22px;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;">{{ $actionLabel !== '' ? $actionLabel : 'Abrir na '.($application->name ?: 'Cutinapp') }}</a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:24px 0 0;font-size:13px;line-height:1.5;color:#6b7280;">Este e-mail foi enviado porque houve uma notificação relacionada à sua conta ou a uma atividade que você acompanha.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
