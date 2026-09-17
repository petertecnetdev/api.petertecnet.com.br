@extends('emails.layouts.application')

@php
    $notificationData = is_array($notification->data) ? $notification->data : [];
    $actionLabel = trim((string) ($notificationData['action_label'] ?? ''));
    $text = $mailBrand['text_color'];
    $muted = $mailBrand['muted_color'];
    $primary = $mailBrand['primary_color'];
    $buttonText = $mailBrand['button_text_color'];
    $recipientName = trim(implode(' ', array_filter([
        trim((string) ($recipient->first_name ?? '')),
        trim((string) ($recipient->last_name ?? '')),
    ])));
    if ($recipientName === '') {
        $recipientName = trim((string) ($recipient->user_name ?? ''));
    }
    if ($recipientName === '') {
        $recipientName = trim((string) ($recipient->name ?? ''));
    }
    if ($recipientName === '' && ! empty($recipient->email)) {
        $recipientName = trim((string) strstr((string) $recipient->email, '@', true));
    }
    $recipientName = $recipientName !== '' ? $recipientName : 'cliente';
@endphp

@section('title', $notification->title ?: 'Nova notificação')
@section('preheader', $notification->message ?: 'Há uma nova movimentação na sua conta.')

@section('content')
<div style="font-size:12px;text-transform:uppercase;letter-spacing:.11em;color:{{ $mailBrand['secondary_color'] }};font-weight:800;">Notificação</div>
<h1 style="margin:9px 0 16px;font-size:27px;line-height:1.22;color:{{ $text }};">{{ $notification->title ?: 'Nova notificação' }}</h1>

<p style="margin:0 0 12px;font-size:16px;line-height:1.65;color:{{ $text }};">Olá, {{ $recipientName }}.</p>

<p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:{{ $muted }};white-space:pre-line;">
    {{ $notification->message ?: 'Há uma nova movimentação que precisa da sua atenção.' }}
</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="border-radius:12px;background:{{ $primary }};">
            <a href="{{ $actionUrl }}" style="display:inline-block;padding:14px 22px;color:{{ $buttonText }};text-decoration:none;font-weight:800;font-size:15px;">
                {{ $actionLabel !== '' ? $actionLabel : 'Abrir na '.$mailBrand['name'] }}
            </a>
        </td>
    </tr>
</table>

<p style="margin:24px 0 0;font-size:12px;line-height:1.6;color:{{ $muted }};">
    Esta mensagem foi enviada porque houve uma notificação relacionada à sua conta ou a uma atividade que você acompanha na {{ $mailBrand['name'] }}.
</p>
@endsection
