<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Produção pronta para vender</title>
</head>
<body style="margin:0;background:#07111f;font-family:Arial,sans-serif;color:#eaf2ff;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#07111f;padding:28px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#0e1b2e;border:1px solid #223653;border-radius:18px;overflow:hidden;">
<tr><td style="padding:30px;">
    <div style="font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#7db9ff;">{{ $application->name }}</div>
    <h1 style="margin:10px 0 8px;font-size:26px;color:#ffffff;">Sua produção está pronta para vender</h1>
    <p style="margin:0 0 20px;line-height:1.6;color:#bfd0e8;">Olá, {{ $user->first_name }}. A configuração comercial de <strong style="color:#fff;">{{ $production->name }}</strong> foi concluída.</p>

    <div style="margin:20px 0;padding:18px;border-radius:14px;background:#0a1627;border:1px solid #21334e;">
        <div style="padding:7px 0;">✓ Acesso do produtor ativo</div>
        <div style="padding:7px 0;">✓ Produção vinculada à sua conta</div>
        <div style="padding:7px 0;">✓ Contrato vigente assinado</div>
        <div style="padding:7px 0;">✓ Recebimentos e chave Pix verificados</div>
        <div style="padding:7px 0;">✓ Checkout habilitado</div>
    </div>

    <p style="line-height:1.6;color:#bfd0e8;">A partir de agora você pode criar, editar e administrar seus próprios eventos. Antes de publicar cada evento, revise informações, lotes e preços.</p>

    <div style="text-align:center;margin:28px 0 12px;">
        <a href="{{ $manageUrl }}" style="display:inline-block;background:#4b9cff;color:#06111f;text-decoration:none;font-weight:800;padding:14px 22px;border-radius:10px;margin:4px;">Gerenciar minha produção</a>
        <a href="{{ $createEventUrl }}" style="display:inline-block;background:#172a45;color:#fff;text-decoration:none;font-weight:700;padding:14px 22px;border-radius:10px;margin:4px;border:1px solid #31547d;">Criar próximo evento</a>
    </div>

    @if($eventUrl)
        <div style="text-align:center;margin:8px 0 20px;"><a href="{{ $eventUrl }}" style="color:#7db9ff;">Ver primeiro evento</a></div>
    @endif

    <p style="font-size:13px;line-height:1.6;color:#91a7c4;">Por segurança, a plataforma continuará validando contrato e recebimentos antes de permitir novas vendas.</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
