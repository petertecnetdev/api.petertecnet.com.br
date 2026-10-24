@extends('emails.layouts.application')

@php
    $event = $order->event;
    $text = $mailBrand['text_color'];
    $muted = $mailBrand['muted_color'];
    $border = $mailBrand['border_color'];
    $primary = $mailBrand['primary_color'];
    $buttonText = $mailBrand['button_text_color'];
    $city = trim(implode(' / ', array_filter([(string) ($event?->city ?? ''), (string) ($event?->uf ?? '')])));
    $venue = trim((string) ($event?->venue ?: $event?->establishment_name ?: ''));
    $address = trim((string) ($event?->formatted_address ?: $event?->address ?: ''));
@endphp

@section('title', 'Compra confirmada — seus ingressos')
@section('preheader', 'Pagamento aprovado: acesse seus ingressos para '.($event?->title ?? 'o evento').' e confira os detalhes.')

@section('content')
<div style="font-size:12px;text-transform:uppercase;letter-spacing:.11em;color:{{ $mailBrand['secondary_color'] }};font-weight:800;">Pagamento aprovado</div>
<h1 style="margin:9px 0 16px;font-size:28px;line-height:1.2;color:{{ $text }};">Seus ingressos estão prontos</h1>

<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:{{ $text }};">
    Sua compra para <strong>{{ $event?->title ?? 'seu evento' }}</strong> foi confirmada.
    {{ $passes->count() }} {{ $passes->count() === 1 ? 'ingresso está disponível' : 'ingressos estão disponíveis' }} na sua conta.
</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;border:1px solid {{ $border }};border-radius:12px;">
    <tr><td style="padding:17px 18px;">
        <div style="font-size:18px;font-weight:800;color:{{ $text }};">{{ $event?->title ?? 'Evento' }}</div>
        @if($event?->start_date)
            <p style="margin:8px 0 0;font-size:14px;color:{{ $text }};">
                <strong>Quando:</strong> {{ $event->start_date->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i') }}
            </p>
        @endif
        @if($venue || $city)
            <p style="margin:7px 0 0;font-size:14px;color:{{ $text }};">
                <strong>Onde:</strong> {{ implode(' — ', array_filter([$venue, $city])) }}
            </p>
        @endif
        @if($address)
            <p style="margin:7px 0 0;font-size:13px;color:{{ $muted }};">{{ $address }}</p>
        @endif
        @if($order->public_id)
            <p style="margin:9px 0 0;font-size:12px;color:{{ $muted }};">Pedido: {{ $order->public_id }}</p>
        @endif
    </td></tr>
</table>

<p style="margin:0 0 10px;font-size:15px;font-weight:800;color:{{ $text }};">Ingressos da compra</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;border:1px solid {{ $border }};border-radius:12px;">
    @foreach($passes as $pass)
        <tr>
            <td style="padding:13px 16px;{{ !$loop->last ? 'border-bottom:1px solid '.$border.';' : '' }}">
                <div style="font-size:14px;font-weight:800;color:{{ $text }};">{{ $pass->ticket?->name ?: 'Ingresso' }}</div>
                <div style="margin-top:4px;font-size:13px;color:{{ $muted }};">Titular: {{ $pass->holder_name ?: 'Participante' }}</div>
                @if($pass->id)
                    <div style="margin-top:6px;font-size:13px;">
                        <a style="color:{{ $primary }};font-weight:700;" href="{{ $passesUrl }}/{{ $pass->id }}">Abrir este ingresso</a>
                    </div>
                @endif
            </td>
        </tr>
    @endforeach
</table>

<table role="presentation" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="border-radius:12px;background:{{ $primary }};">
            <a href="{{ $passesUrl }}" style="display:inline-block;padding:14px 22px;color:{{ $buttonText }};text-decoration:none;font-weight:800;font-size:15px;">Ver meus ingressos</a>
        </td>
    </tr>
</table>

@if($eventUrl)
    <p style="margin:16px 0 0;font-size:14px;">
        <a href="{{ $eventUrl }}" style="font-weight:700;color:{{ $primary }};">Ver detalhes e informações do evento</a>
    </p>
@endif

<p style="margin:22px 0 8px;font-size:14px;font-weight:800;color:{{ $text }};">Como entrar no evento</p>
<ol style="margin:0;padding-left:19px;font-size:13px;line-height:1.8;color:{{ $muted }};">
    <li>Acesse a {{ $applicationName }} e entre com a conta usada na compra.</li>
    <li>Abra <strong>Meus ingressos</strong> e selecione seu ingresso para visualizar o QR Code.</li>
    <li>Apresente o QR Code na portaria quando a entrada for liberada.</li>
</ol>
<p style="margin:16px 0 0;font-size:12px;line-height:1.65;color:{{ $muted }};">
    Guarde este e-mail. Por segurança, o QR Code fica disponível somente na sua conta; não compartilhe seu ingresso com terceiros.
    Se o horário ou local mudar, consulte a página do evento antes de sair.
</p>
@endsection
