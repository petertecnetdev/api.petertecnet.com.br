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
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:660px;background:linear-gradient(145deg,#17122f,#0e1730);border:1px solid #453a74;border-radius:22px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.35);">
                @if(!empty($details['event_image']))
                <tr>
                    <td>
                        <img src="{{ $details['event_image'] }}" alt="" width="660" style="display:block;width:100%;max-height:260px;object-fit:cover;background:#111225;">
                    </td>
                </tr>
                @endif
                <tr>
                    <td style="padding:34px 34px 18px;">
                        <div style="font-size:12px;letter-spacing:1.8px;text-transform:uppercase;color:#d985ff;font-weight:700;">Convite artístico</div>
                        <h1 style="margin:12px 0 10px;font-size:28px;line-height:1.2;color:#fff;">
                            @if(!empty($details['is_reconfirmation']))
                                Sua participação mudou e precisa ser confirmada novamente
                            @elseif(!empty($details['is_reminder']))
                                Seu convite ainda aguarda resposta
                            @else
                                Você foi convidado para um evento
                            @endif
                        </h1>
                        <p style="margin:0;color:#c9c5dc;font-size:16px;line-height:1.65;">
                            <strong style="color:#fff;">{{ $producerName }}</strong> convidou você para participar de
                            <strong style="color:#fff;">{{ $eventTitle }}</strong>.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:0 34px 18px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10);border-radius:16px;">
                            <tr>
                                <td style="padding:18px 20px;color:#d8d4e8;font-size:14px;line-height:1.7;">
                                    @if(!empty($details['start_date']))
                                        <div><strong style="color:#fff;">Evento:</strong> {{ IlluminateSupportCarbon::parse($details['start_date'])->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i') }}</div>
                                    @endif
                                    @if($participationType)<div><strong style="color:#fff;">Participação:</strong> {{ $participationType }}</div>@endif
                                    @if($stage)<div><strong style="color:#fff;">Palco / espaço:</strong> {{ $stage }}</div>@endif
                                    @if($scheduledAt)
                                        <div><strong style="color:#fff;">Horário previsto:</strong> {{ IlluminateSupportCarbon::parse($scheduledAt)->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i') }}</div>
                                    @endif
                                    @if(!empty($details['venue']))<div><strong style="color:#fff;">Local:</strong> {{ $details['venue'] }}</div>@endif
                                    @if(!empty($details['formatted_address']))<div><strong style="color:#fff;">Endereço:</strong> {{ $details['formatted_address'] }}</div>@endif
                                    @if(array_key_exists('fee_cents', $details) && $details['fee_cents'] !== null)
                                        <div><strong style="color:#fff;">Cachê informado:</strong> R$ {{ number_format(((int) $details['fee_cents']) / 100, 2, ',', '.') }}</div>
                                    @endif
                                    @if(!empty($details['event_contact_email']))<div><strong style="color:#fff;">Contato:</strong> {{ $details['event_contact_email'] }}</div>@endif
                                    @if(!empty($details['event_contact_phone']))<div><strong style="color:#fff;">Telefone:</strong> {{ $details['event_contact_phone'] }}</div>@endif

                                    @if(!empty($details['changed_fields']))
                                        <div style="margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,.10);">
                                            <strong style="color:#fff;">O que mudou:</strong>
                                            {{ implode(', ', $details['changed_fields']) }}.
                                        </div>
                                    @endif

                                    @if(!empty($details['requires_registration']))
                                        <div style="margin-top:10px;">Crie sua conta usando este mesmo e-mail. Depois da confirmação do endereço, o convite será recuperado automaticamente e continuará <strong style="color:#fff;">aguardando sua decisão</strong>.</div>
                                    @else
                                        <div style="margin-top:10px;">O convite só vira participação confirmada depois que você escolher <strong style="color:#fff;">Aceitar</strong>. A produção não pode confirmar presença em seu nome.</div>
                                    @endif

                                    <div style="margin-top:8px;">Se este for seu primeiro vínculo artístico, {{ $producerName }} pode aparecer como produção de referência por ter apresentado você à plataforma. Isso não transfere propriedade nem administração do seu perfil.</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:4px 34px 34px;">
                        <a href="{{ $actionUrl }}" style="display:inline-block;padding:14px 24px;border-radius:12px;background:linear-gradient(90deg,#c33cff,#1b9cff);color:#fff;text-decoration:none;font-weight:700;font-size:15px;">
                            @if(!empty($details['requires_registration']))
                                Criar conta e ver convite
                            @elseif(!empty($details['is_reconfirmation']))
                                Revisar alterações e responder
                            @else
                                Ver convite e responder
                            @endif
                        </a>
                        <p style="margin:20px 0 0;color:#8f8aa8;font-size:12px;line-height:1.6;">Se você não reconhece este convite, não aceite. A decisão fica registrada com data, hora e conta responsável.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
@if(!empty($details['tracking_pixel_url']))
<img src="{{ $details['tracking_pixel_url'] }}" width="1" height="1" alt="" style="display:block;width:1px;height:1px;opacity:0;" />
@endif
</body>
</html>
