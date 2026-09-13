@extends('emails.layouts.application')

@php
    $text = $mailBrand['text_color'];
    $muted = $mailBrand['muted_color'];
    $border = $mailBrand['border_color'];
    $primary = $mailBrand['primary_color'];
    $buttonText = $mailBrand['button_text_color'];
@endphp

@section('title', 'Seus ingressos')
@section('preheader', 'Seus ingressos para '.($order->event?->title ?? 'o evento').' já estão disponíveis.')

@section('content')
<div style="font-size:12px;text-transform:uppercase;letter-spacing:.11em;color:{{ $mailBrand['secondary_color'] }};font-weight:800;">Compra confirmada</div>
<h1 style="margin:9px 0 16px;font-size:28px;line-height:1.2;color:{{ $text }};">Seus ingressos estão prontos</h1>

<p style="margin:0 0 8px;font-size:15px;line-height:1.7;color:{{ $text }};">
    Compra confirmada para <strong>{{ $order->event?->title ?? 'seu evento' }}</strong>.
</p>
<p style="margin:0 0 22px;font-size:14px;line-height:1.65;color:{{ $muted }};">
    {{ $passes->count() }} {{ $passes->count() === 1 ? 'ingresso foi emitido' : 'ingressos foram emitidos' }} para esta compra.
</p>

@if($passes->isNotEmpty())
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;border:1px solid {{ $border }};border-radius:14px;overflow:hidden;">
        @foreach($passes as $pass)
            <tr>
                <td style="padding:13px 16px;{{ !$loop->last ? 'border-bottom:1px solid '.$border.';' : '' }}">
                    <div style="font-size:14px;font-weight:800;color:{{ $text }};">{{ $pass->holder_name ?: 'Participante' }}</div>
                    @if($pass->holder_email)
                        <div style="margin-top:4px;font-size:12px;color:{{ $muted }};">{{ $pass->holder_email }}</div>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
@endif

<table role="presentation" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="border-radius:12px;background:{{ $primary }};">
            <a href="{{ $passesUrl }}" style="display:inline-block;padding:14px 22px;color:{{ $buttonText }};text-decoration:none;font-weight:800;font-size:15px;">Ver meus ingressos</a>
        </td>
    </tr>
</table>

<p style="margin:20px 0 0;font-size:12px;line-height:1.6;color:{{ $muted }};">
    Apresente o ingresso disponível na {{ $applicationName }} no acesso ao evento. Não compartilhe seu QR Code com terceiros.
</p>
@endsection
