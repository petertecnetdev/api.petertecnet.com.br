@extends('emails.layouts.application')

@php
    $text = $mailBrand['text_color'];
    $muted = $mailBrand['muted_color'];
    $border = $mailBrand['border_color'];
    $primary = $mailBrand['primary_color'];
    $buttonText = $mailBrand['button_text_color'];
    $recipientName = trim(($recipient->first_name ?? '').' '.($recipient->last_name ?? ''))
        ?: ($recipient->user_name ?: 'usuário');
@endphp

@section('title', 'Novas mensagens na '.$mailBrand['name'])
@section('preheader', 'Você tem mensagens aguardando sua atenção na '.$mailBrand['name'].'.')

@section('content')
<div style="font-size:12px;text-transform:uppercase;letter-spacing:.11em;color:{{ $mailBrand['secondary_color'] }};font-weight:800;">{{ $mailBrand['name'] }} Direct</div>

<h1 style="margin:9px 0 16px;font-size:27px;line-height:1.22;color:{{ $text }};">
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

<p style="margin:0 0 20px;font-size:16px;line-height:1.65;color:{{ $text }};">Olá, {{ $recipientName }}.</p>

@foreach($conversations as $conversation)
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 12px;border:1px solid {{ $border }};border-radius:14px;">
        <tr>
            <td width="58" valign="top" style="width:58px;padding:14px 0 14px 14px;">
                @if($conversation['sender_avatar'])
                    <img src="{{ $conversation['sender_avatar'] }}" alt="" width="44" height="44" style="display:block;width:44px;height:44px;object-fit:cover;border-radius:999px;">
                @else
                    <div style="width:44px;height:44px;line-height:44px;text-align:center;border-radius:999px;background:{{ $primary }};color:{{ $buttonText }};font-weight:800;">
                        {{ mb_strtoupper(mb_substr($conversation['sender_name'] ?? 'U', 0, 1)) }}
                    </div>
                @endif
            </td>
            <td valign="top" style="padding:14px;">
                <div style="font-size:15px;font-weight:800;color:{{ $text }};">{{ $conversation['sender_name'] }}</div>
                @if($conversation['sender_username'])
                    <div style="font-size:12px;color:{{ $muted }};margin-top:2px;">@{{ $conversation['sender_username'] }}</div>
                @endif
                <div style="font-size:14px;line-height:1.55;color:{{ $muted }};margin-top:8px;">{{ $conversation['preview'] }}</div>
                @if(($conversation['message_count'] ?? 1) > 1)
                    <div style="font-size:12px;color:{{ $muted }};margin-top:7px;">{{ $conversation['message_count'] }} mensagens novas nesta conversa</div>
                @endif
            </td>
        </tr>
    </table>
@endforeach

<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top:22px;">
    <tr>
        <td style="border-radius:12px;background:{{ $primary }};">
            <a href="{{ $actionUrl }}" style="display:inline-block;padding:14px 24px;color:{{ $buttonText }};text-decoration:none;font-size:15px;font-weight:800;">Responder agora</a>
        </td>
    </tr>
</table>

<p style="margin:24px 0 0;font-size:12px;line-height:1.6;color:{{ $muted }};">
    A {{ $mailBrand['name'] }} agrupa mensagens próximas para reduzir e-mails repetidos. Suas preferências de notificação podem ser ajustadas no aplicativo.
</p>
@endsection
