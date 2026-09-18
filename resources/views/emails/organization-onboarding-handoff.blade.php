<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sua produção está pronta</title>
</head>
<body style="margin:0;background:#070b16;color:#f5f7ff;font-family:Arial,Helvetica,sans-serif">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#070b16;padding:28px 12px">
<tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#111827;border:1px solid #26324a;border-radius:18px;overflow:hidden">
<tr><td style="padding:32px">
<p style="margin:0 0 10px;color:#8fb5ff;font-size:13px;text-transform:uppercase;letter-spacing:1.2px">{{ $application->name }}</p>
<h1 style="margin:0 0 18px;font-size:28px;line-height:1.2">Olá, {{ $owner->first_name }}. Sua produção está pronta.</h1>
<p style="margin:0 0 22px;color:#c8d2e6;line-height:1.65">Nossa equipe realizou a configuração inicial de <strong style="color:#fff">{{ $organization->name }}</strong> para facilitar sua entrada. A partir de agora, a conta e a operação ficam sob seu controle.</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#0b1220;border-radius:14px;margin:0 0 22px">
<tr><td style="padding:20px">
<p style="margin:0 0 8px"><strong>Produção:</strong> {{ $organization->name }}</p>
@if($event)
<p style="margin:0"><strong>Primeiro evento:</strong> {{ $event->title }}</p>
@endif
</td></tr>
</table>

<p style="margin:0 0 12px"><strong>Para começar a vender ingressos, faltam duas etapas obrigatórias:</strong></p>
<ol style="margin:0 0 24px;padding-left:20px;color:#c8d2e6;line-height:1.8">
<li>Revisar e assinar o contrato vigente.</li>
<li>Concluir a verificação financeira e cadastrar sua chave Pix para recebimentos.</li>
</ol>

<p style="margin:0 0 14px"><a href="{{ $onboardingUrl }}" style="display:inline-block;background:#5b7cff;color:#fff;text-decoration:none;padding:13px 20px;border-radius:10px;font-weight:700">Concluir meu onboarding</a></p>
<p style="margin:0 0 8px"><a href="{{ $agreementUrl }}" style="color:#8fb5ff">Revisar e assinar contrato</a></p>
<p style="margin:0 0 24px"><a href="{{ $financeUrl }}" style="color:#8fb5ff">Configurar recebimentos e Pix</a></p>

<p style="margin:0;color:#9aa8c1;font-size:13px;line-height:1.55">Seu primeiro evento foi preparado como rascunho. Depois de concluir as etapas acima, revise os dados, configure os ingressos e publique. Os próximos eventos podem ser criados e administrados diretamente por você.</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
