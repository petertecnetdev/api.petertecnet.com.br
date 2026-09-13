@extends('emails.layouts.application')

@section('title', $notificationTitle)
@section('preheader', $notificationMessage)

@section('content')
@php
    $primary = $mailBrand['primary_color'];
    $secondary = $mailBrand['secondary_color'];
    $accent = $mailBrand['accent_color'];
    $text = $mailBrand['text_color'];
    $muted = $mailBrand['muted_color'];
    $border = $mailBrand['border_color'];
    $buttonText = $mailBrand['button_text_color'];
    $isReminder = str_starts_with($action, 'reminder_');
    $ownerName = trim((string) ($owner->name ?: ($owner->first_name ?? '')));
@endphp

<div style="font-size:12px;line-height:1.2;text-transform:uppercase;letter-spacing:.11em;color:{{ $secondary }};font-weight:800;">
    {{ $isReminder ? 'Lembrete do seu evento' : 'Atualização do seu evento' }}
</div>

<h1 style="margin:9px 0 16px;font-size:28px;line-height:1.2;color:{{ $text }};letter-spacing:-.02em;">
    {{ $notificationTitle }}
</h1>

<p style="margin:0 0 12px;font-size:16px;line-height:1.65;color:{{ $text }};">
    Olá, {{ $ownerName !== '' ? $ownerName : 'produtor' }}.
</p>

<p style="margin:0 0 22px;font-size:15px;line-height:1.7;color:{{ $muted }};">
    {{ $notificationMessage }}
</p>

@if($isReminder)
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;background:#faf7ff;border:1px solid {{ $border }};border-left:4px solid {{ $primary }};border-radius:14px;">
        <tr>
            <td style="padding:17px 18px;">
                <div style="font-size:15px;font-weight:800;color:{{ $text }};">Falta pouco para o evento começar</div>
                <div style="margin-top:5px;font-size:13px;line-height:1.55;color:{{ $muted }};">Use este lembrete para revisar a operação e acompanhar as vendas antes da abertura.</div>
            </td>
        </tr>
    </table>
@endif

@if(!empty($flyerUrl))
    <div style="margin:0 0 22px;">
        <a href="{{ $eventUrl }}" style="display:block;text-decoration:none;">
            <img src="{{ $flyerUrl }}" alt="Flyer de {{ $event->title }}" width="580" style="display:block;width:100%;max-width:580px;height:auto;border:0;border-radius:16px;">
        </a>
    </div>
@endif

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;border:1px solid {{ $border }};border-radius:16px;overflow:hidden;">
    <tr>
        <td style="padding:16px 18px;background:#fbfbfd;border-bottom:1px solid {{ $border }};">
            <div style="font-size:12px;text-transform:uppercase;letter-spacing:.09em;color:{{ $muted }};font-weight:700;">Evento</div>
            <div style="margin-top:4px;font-size:17px;line-height:1.35;color:{{ $text }};font-weight:800;">{{ $event->title }}</div>
        </td>
    </tr>
    <tr>
        <td style="padding:15px 18px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="padding:0 0 10px;font-size:13px;color:{{ $muted }};">Produção</td>
                    <td align="right" style="padding:0 0 10px;font-size:13px;color:{{ $text }};font-weight:700;">{{ $production->fantasy ?: $production->name }}</td>
                </tr>
                @if($event->start_date)
                    <tr>
                        <td style="padding:0 0 10px;font-size:13px;color:{{ $muted }};">Início</td>
                        <td align="right" style="padding:0 0 10px;font-size:13px;color:{{ $text }};font-weight:700;">{{ $event->start_date->format('d/m/Y H:i') }}</td>
                    </tr>
                @endif
                @if($event->venue || $event->city)
                    <tr>
                        <td style="padding:0 0 10px;font-size:13px;color:{{ $muted }};">Local</td>
                        <td align="right" style="padding:0 0 10px;font-size:13px;color:{{ $text }};font-weight:700;">{{ collect([$event->venue, $event->city])->filter()->implode(' — ') }}</td>
                    </tr>
                @endif
                <tr>
                    <td style="font-size:13px;color:{{ $muted }};">Status</td>
                    <td align="right" style="font-size:13px;color:{{ $event->is_cancelled ? '#b42318' : '#067647' }};font-weight:800;">
                        {{ $event->is_cancelled ? 'Cancelado/inativo' : ($event->is_published ? 'Publicado' : 'Não publicado') }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

@if($isReminder)
    <div style="margin:0 0 22px;">
        <div style="font-size:15px;font-weight:800;color:{{ $text }};margin-bottom:9px;">Checklist rápido</div>
        <div style="font-size:14px;line-height:1.7;color:{{ $muted }};">
            • Confira vendas, cortesias, ingressos emitidos e capacidade.<br>
            • Revise equipe, horário, local e fluxo de check-in.<br>
            • Reforce a divulgação e confirme as informações públicas.<br>
            • Mantenha a {{ $appName }} aberta no dia do evento para acompanhar a operação.
        </div>
    </div>
@endif

@if(!empty($changedLabels))
    <div style="margin:0 0 22px;">
        <div style="font-size:15px;font-weight:800;color:{{ $text }};margin-bottom:9px;">O que mudou</div>
        @foreach($changedLabels as $label)
            <div style="margin:0 0 7px;padding:10px 12px;border-radius:10px;background:#fafafa;border:1px solid {{ $border }};font-size:13px;color:{{ $text }};">
                {{ ucfirst($label) }}
            </div>
        @endforeach
    </div>
@endif

<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:4px 0 18px;">
    <tr>
        <td style="border-radius:12px;background:{{ $primary }};">
            <a href="{{ $eventUrl }}" style="display:inline-block;padding:14px 22px;color:{{ $buttonText }};text-decoration:none;font-size:15px;font-weight:800;">
                {{ $isReminder ? 'Abrir evento agora' : ($action === 'created' ? 'Ver meu evento' : 'Ver evento na '.$appName) }}
            </a>
        </td>
    </tr>
</table>

<p style="margin:0 0 8px;font-size:13px;line-height:1.65;color:{{ $muted }};">
    <a href="{{ $eventManagementUrl }}" style="color:{{ $secondary }};font-weight:700;text-decoration:none;">Gerenciar este evento</a>
    @if(!empty($shareUrl))
        &nbsp;·&nbsp;
        <a href="{{ $shareUrl }}" style="color:{{ $secondary }};font-weight:700;text-decoration:none;">Compartilhar no WhatsApp</a>
    @endif
    @if($action === 'created' && !empty($createEventUrl))
        &nbsp;·&nbsp;
        <a href="{{ $createEventUrl }}" style="color:{{ $secondary }};font-weight:700;text-decoration:none;">Criar próximo evento</a>
    @endif
</p>

<p style="margin:18px 0 0;padding-top:16px;border-top:1px solid {{ $border }};font-size:12px;line-height:1.6;color:{{ $muted }};">
    Você recebeu esta mensagem porque é o produtor responsável por este evento na {{ $appName }}.
</p>
@endsection
