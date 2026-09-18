@extends('emails.layouts.application')

@php
    $text = $mailBrand['text_color'];
    $muted = $mailBrand['muted_color'];
    $secondary = $mailBrand['secondary_color'];
    $border = $mailBrand['border_color'];
@endphp

@section('title', $heading)
@section('preheader', $messageText)

@section('content')
<div style="font-size:12px;text-transform:uppercase;letter-spacing:.11em;color:{{ $secondary }};font-weight:800;">Atualização de convite artístico</div>

<h1 style="margin:9px 0 14px;font-size:27px;line-height:1.22;color:{{ $text }};">{{ $heading }}</h1>

<p style="margin:0 0 22px;font-size:16px;line-height:1.7;color:{{ $muted }};">{{ $messageText }}</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border:1px solid {{ $border }};border-radius:16px;background:#fbfbfd;">
    <tr>
        <td style="padding:18px 20px;font-size:14px;line-height:1.8;color:{{ $muted }};">
            <div><strong style="color:{{ $text }};">Evento:</strong> {{ $eventTitle }}</div>
            @if($producerName)<div><strong style="color:{{ $text }};">Produção:</strong> {{ $producerName }}</div>@endif
            @if($eventDate)<div><strong style="color:{{ $text }};">Data:</strong> {{ $eventDate }}</div>@endif
        </td>
    </tr>
</table>

<p style="margin:20px 0 0;font-size:12px;line-height:1.6;color:{{ $muted }};">
    Esta mensagem é transacional e se refere a um convite artístico enviado anteriormente para este endereço pela {{ $mailBrand['name'] }}.
</p>
@endsection
