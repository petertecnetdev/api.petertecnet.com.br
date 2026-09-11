<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novas mensagens na {{ $application->name ?: 'Cutinapp' }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f5f8;font-family:Arial,Helvetica,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f5f8;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#fff;border:1px solid #e5e7eb;border-radius:20px;overflow:hidden;">
                <tr>
                    <td style="padding:28px;background:#111827;color:#fff;">
                        <div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.72;">{{ $application->name ?: 'Cutinapp' }} Direct</div>
                        <h1 style="margin:10px 0 0;font-size:25px;line-height:1.25;">
                            @if($kind === 'reminder_2')
                                Suas conversas ainda estão esperando por você
                            @elseif($kind === 'reminder_1')
                                Você ainda tem mensagens não lidas
                            @elseif(count($conversations) > 1)
                                Você tem novas mensagens
                            @else
                                {{ $conversations[0]['sender_name'] ?? 'Alguém' }} enviou uma mensagem
                            @endif
                        </h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:26px;">
                        <p style="margin:0 0 18px;font-size:16px;line-height:1.55;">Olá, {{ trim(($recipient->first_name ?? '').' '.($recipient->last_name ?? '')) ?: ($recipient->user_name ?: 'usuário') }}.</p>

                        @foreach($conversations as $conversation)
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 12px;border:1px solid #e5e7eb;border-radius:14px;">
                                <tr>
                                    <td style="padding:14px;width:48px;vertical-align:top;">
                                        @if($conversation['sender_avatar'])
                                            <img src="{{ $conversation['sender_avatar'] }}" alt="" width="44" height="44" style="display:block;width:44px;height:44px;object-fit:cover;border-radius:50%;">
                                        @else
                                            <div style="width:44px;height:44px;line-height:44px;text-align:center;border-radius:50%;background:#111827;color:#fff;font-weight:700;">{{ mb_strtoupper(mb_substr($conversation['sender_name'] ?? 'U', 0, 1)) }}</div>
                                        @endif
                                    </td>
                                    <td style="padding:14px 14px 14px 0;vertical-align:top;">
                                        <div style="font-size:15px;font-weight:700;">{{ $conversation['sender_name'] }}</div>
                                        @if($conversation['sender_username'])
                                            <div style="font-size:12px;color:#6b7280;margin-top:2px;">@{{ $conversation['sender_username'] }}</div>
                                        @endif
                                        <div style="font-size:14px;line-height:1.5;color:#374151;margin-top:8px;">{{ $conversation['preview'] }}</div>
                                        @if(($conversation['message_count'] ?? 1) > 1)
                                            <div style="font-size:12px;color:#6b7280;margin-top:7px;">{{ $conversation['message_count'] }} mensagens novas nesta conversa</div>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        @endforeach

                        <table role="presentation" cellspacing="0" cellpadding="0" style="margin-top:22px;">
                            <tr>
                                <td style="border-radius:12px;background:#111827;">
                                    <a href="{{ $actionUrl }}" style="display:inline-block;padding:14px 24px;color:#fff;text-decoration:none;font-size:15px;font-weight:700;">Responder agora</a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:24px 0 0;font-size:12px;line-height:1.55;color:#6b7280;">
                            A Cutinapp agrupa mensagens próximas para reduzir e-mails repetidos. Você pode ajustar e-mail, push, lembretes, prévias e resumos nas preferências do Direct.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
