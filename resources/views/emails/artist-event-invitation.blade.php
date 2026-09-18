@extends('emails.layouts.application')

@php
    $text = $mailBrand['text_color'];
    $muted = $mailBrand['muted_color'];
    $primary = $mailBrand['primary_color'];
    $secondary = $mailBrand['secondary_color'];
    $accent = $mailBrand['accent_color'];
    $border = $mailBrand['border_color'];
    $buttonText = $mailBrand['button_text_color'];
@endphp

@section('title', 'Convite para '.$eventTitle)
@section('preheader', $producerName.' convidou você para participar de '.$eventTitle.'.')

@section('content')
@if(!empty($details['event_image']))
    <div style="margin:-30px -30px 26px;overflow:hidden;">
        <img src="{{ $details['event_image'] }}" alt="" width="640" style="display:block;width:100%;max-height:280px;object-fit:cover;background:#111827;border:0;">
    </div>
@endif

<div style="font-size:12px;text-transform:uppercase;letter-spacing:.11em;color:{{ $secondary }};font-weight:800;">Convite artístico</div>

<h1 style="margin:9px 0 14px;font-size:28px;line-height:1.22;color:{{ $text }};">
    @if(!empty($details['is_reconfirmation']))
        Sua participação mudou e precisa ser confirmada novamente
    @elseif(!empty($details['is_reminder']))
        Seu convite ainda aguarda resposta
    @else
        Você foi convidado para um evento
    @endif
</h1>

<p style="margin:0 0 22px;font-size:16px;line-height:1.7;color:{{ $muted }};">
    <strong style="color:{{ $text }};">{{ $producerName }}</strong> convidou você para participar de
    <strong style="color:{{ $text }};">{{ $eventTitle }}</strong> pela {{ $mailBrand['name'] }}.
</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border:1px solid {{ $border }};border-radius:16px;background:#fbfbfd;">
    <tr>
        <td style="padding:18px 20px;font-size:14px;line-height:1.8;color:{{ $muted }};">
            @if(!empty($details['start_date']))
                <div><strong style="color:{{ $text }};">Evento:</strong> {{ \Illuminate\Support\Carbon::parse($details['start_date'])->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i') }}</div>
            @endif
            @if($participationType)<div><strong style="color:{{ $text }};">Participação:</strong> {{ $participationType }}</div>@endif
            @if($stage)<div><strong style="color:{{ $text }};">Palco / espaço:</strong> {{ $stage }}</div>@endif
            @if($scheduledAt)
                <div><strong style="color:{{ $text }};">Horário previsto:</strong> {{ \Illuminate\Support\Carbon::parse($scheduledAt)->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i') }}</div>
            @endif
            @if(!empty($details['venue']))<div><strong style="color:{{ $text }};">Local:</strong> {{ $details['venue'] }}</div>@endif
            @if(!empty($details['formatted_address']))<div><strong style="color:{{ $text }};">Endereço:</strong> {{ $details['formatted_address'] }}</div>@endif
            @if(array_key_exists('fee_cents', $details) && $details['fee_cents'] !== null)
                <div><strong style="color:{{ $text }};">Cachê informado:</strong> R$ {{ number_format(((int) $details['fee_cents']) / 100, 2, ',', '.') }}</div>
            @endif
            @if(!empty($details['event_contact_email']))<div><strong style="color:{{ $text }};">Contato:</strong> {{ $details['event_contact_email'] }}</div>@endif
            @if(!empty($details['event_contact_phone']))<div><strong style="color:{{ $text }};">Telefone:</strong> {{ $details['event_contact_phone'] }}</div>@endif
        </td>
    </tr>
</table>

@if(!empty($details['changed_fields']))
    <div style="margin-top:16px;padding:14px 16px;border-left:4px solid {{ $accent }};background:#f7f7fb;border-radius:10px;color:{{ $muted }};font-size:14px;line-height:1.6;">
        <strong style="color:{{ $text }};">O que mudou:</strong> {{ implode(', ', $details['changed_fields']) }}.
    </div>
@endif

<div style="margin-top:20px;font-size:14px;line-height:1.7;color:{{ $muted }};">
    @if(!empty($details['requires_registration']))
        Crie sua conta usando este mesmo e-mail. Depois da confirmação do endereço, o convite será recuperado automaticamente e continuará <strong style="color:{{ $text }};">aguardando sua decisão</strong>.
    @else
        O convite só vira participação confirmada depois que você escolher <strong style="color:{{ $text }};">Aceitar</strong>. A produção não pode confirmar presença em seu nome.
    @endif
</div>

<p style="margin:12px 0 0;font-size:13px;line-height:1.65;color:{{ $muted }};">
    Se este for seu primeiro vínculo artístico, {{ $producerName }} pode aparecer como produção de referência por ter apresentado você à plataforma. Isso não transfere propriedade nem administração do seu perfil.
</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top:24px;">
    <tr>
        <td style="border-radius:12px;background:{{ $primary }};">
            <a href="{{ $actionUrl }}" style="display:inline-block;padding:14px 22px;color:{{ $buttonText }};text-decoration:none;font-weight:800;font-size:15px;">
                @if(!empty($details['requires_registration']))
                    Criar conta e ver convite
                @elseif(!empty($details['is_reconfirmation']))
                    Revisar alterações e responder
                @else
                    Ver convite e responder
                @endif
            </a>
        </td>
    </tr>
</table>

<p style="margin:22px 0 0;font-size:12px;line-height:1.6;color:{{ $muted }};">
    Se você não reconhece este convite, não aceite. A decisão fica registrada com data, hora e conta responsável.
</p>

@if(!empty($details['tracking_pixel_url']))
    <img src="{{ $details['tracking_pixel_url'] }}" width="1" height="1" alt="" style="display:block;width:1px;height:1px;opacity:0;" />
@endif
@endsection
