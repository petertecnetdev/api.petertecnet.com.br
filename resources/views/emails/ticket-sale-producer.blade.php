@extends('emails.layouts.application')

@php
    $text = $mailBrand['text_color'];
    $muted = $mailBrand['muted_color'];
    $border = $mailBrand['border_color'];
    $primary = $mailBrand['primary_color'];
    $buttonText = $mailBrand['button_text_color'];
@endphp

@section('title', 'Nova venda de ingresso')
@section('preheader', 'Uma nova venda foi confirmada para '.($order->event?->title ?? 'seu evento').'.')

@section('content')
<div style="font-size:12px;text-transform:uppercase;letter-spacing:.11em;color:{{ $mailBrand['secondary_color'] }};font-weight:800;">Venda confirmada</div>
<h1 style="margin:9px 0 16px;font-size:28px;line-height:1.2;color:{{ $text }};">
    Você vendeu {{ $ticketQuantity }} {{ $ticketQuantity === 1 ? 'ingresso' : 'ingressos' }}
</h1>

<p style="margin:0 0 22px;font-size:15px;line-height:1.7;color:{{ $muted }};">
    Uma nova compra foi aprovada para <strong style="color:{{ $text }};">{{ $order->event?->title ?? 'seu evento' }}</strong>.
</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;border:1px solid {{ $border }};border-radius:14px;overflow:hidden;">
    <tr>
        <td style="padding:13px 16px;color:{{ $muted }};border-bottom:1px solid {{ $border }};">Pedido</td>
        <td align="right" style="padding:13px 16px;color:{{ $text }};font-weight:800;border-bottom:1px solid {{ $border }};">#{{ strtoupper(substr((string) $order->public_id, 0, 8)) }}</td>
    </tr>
    <tr>
        <td style="padding:13px 16px;color:{{ $muted }};border-bottom:1px solid {{ $border }};">Participante</td>
        <td align="right" style="padding:13px 16px;color:{{ $text }};border-bottom:1px solid {{ $border }};">{{ trim(($order->user?->first_name ?? '').' '.($order->user?->last_name ?? '')) ?: ($order->user?->email ?? 'Participante') }}</td>
    </tr>
    <tr>
        <td style="padding:13px 16px;color:{{ $muted }};border-bottom:1px solid {{ $border }};">Total</td>
        <td align="right" style="padding:13px 16px;color:{{ $text }};font-weight:800;border-bottom:1px solid {{ $border }};">R$ {{ number_format((float) $order->total, 2, ',', '.') }}</td>
    </tr>
    <tr>
        <td style="padding:13px 16px;color:{{ $muted }};border-bottom:1px solid {{ $border }};">Taxa da plataforma</td>
        <td align="right" style="padding:13px 16px;color:{{ $text }};border-bottom:1px solid {{ $border }};">R$ {{ number_format((float) $order->platform_fee, 2, ',', '.') }}</td>
    </tr>
    <tr>
        <td style="padding:13px 16px;color:{{ $muted }};">Líquido da produção</td>
        <td align="right" style="padding:13px 16px;color:#067647;font-weight:800;">R$ {{ number_format((float) $order->producer_net, 2, ',', '.') }}</td>
    </tr>
</table>

<table role="presentation" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="border-radius:12px;background:{{ $primary }};">
            <a href="{{ $saleUrl }}" style="display:inline-block;padding:14px 22px;color:{{ $buttonText }};text-decoration:none;font-weight:800;font-size:15px;">Ver venda na {{ $applicationName }}</a>
        </td>
    </tr>
</table>

<p style="margin:22px 0 0;font-size:12px;line-height:1.6;color:{{ $muted }};">
    Este aviso é enviado somente após a confirmação do pagamento e emissão válida dos ingressos.
</p>
@endsection
