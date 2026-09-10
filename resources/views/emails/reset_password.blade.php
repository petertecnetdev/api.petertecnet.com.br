<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinição de senha - {{ $brand['name'] }}</title>
    <style>
        body, html { margin: 0; padding: 0; }
        body { background-color: {{ $brand['background_color'] }}; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #ffffff; line-height: 1.6; }
        .container { background-color: {{ $brand['surface_color'] }}; max-width: 600px; margin: 40px auto; padding: 30px; border: 1px solid {{ $brand['primary_color'] }}; border-radius: 12px; box-shadow: 0 2px 18px rgba(0,0,0,.32); }
        .logo { display: block; margin: 0 auto 20px; max-width: 160px; max-height: 90px; width: auto; height: auto; }
        h1 { font-size: 1.8rem; text-align: center; color: {{ $brand['primary_color'] }}; margin-bottom: 20px; }
        p { font-size: 1rem; margin: 20px 0; color: #ffffff; }
        .verification-code { background-color: {{ $brand['primary_color'] }}; color: #071621; font-size: 1.6rem; font-weight: 800; text-align: center; padding: 16px; margin: 20px 0; border-radius: 10px; letter-spacing: 5px; }
        .action { display: block; margin: 24px auto; padding: 14px 20px; border-radius: 10px; background: {{ $brand['primary_color'] }}; color: #071621 !important; font-weight: 800; text-align: center; text-decoration: none; }
        .hint { opacity: .82; font-size: .92rem; }
        .footer { text-align: center; padding: 20px 0; font-size: .9rem; color: #ffffff; opacity: .8; }
        @media (max-width: 600px) { .container { margin: 16px; padding: 22px; } h1 { font-size: 1.5rem; } p { font-size: .95rem; } .verification-code { font-size: 1.3rem; padding: 12px; } }
    </style>
</head>
<body>
    <div class="container">
        <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" class="logo" />
        <h1>Olá {{ $userName }},</h1>
        <p>Recebemos uma solicitação para redefinir a senha da sua conta no {{ $brand['name'] }}.</p>
        <p>Clique no botão abaixo para abrir a página segura e informe o código de validação. Com isso, você valida o acesso ao seu e-mail e cadastra a nova senha no mesmo processo.</p>
        <div class="verification-code">{{ $code }}</div>
        <a href="{{ $resetUrl }}" class="action">Criar nova senha</a>
        <p class="hint">O código é válido por 10 minutos e pode ser usado apenas enquanto esta solicitação estiver ativa. Uma nova solicitação substitui o código anterior.</p>
        <p class="hint">Se você não solicitou esta alteração, ignore este e-mail e não compartilhe o código com ninguém.</p>
    </div>
    <div class="footer">© {{ date('Y') }} {{ $brand['name'] }} · Ecossistema Peter Tecnet.</div>
</body>
</html>
