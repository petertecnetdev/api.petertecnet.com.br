<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinição de senha - Peter Tecnet</title>
    <style>
        body, html { margin: 0; padding: 0; }
        body { background-color: #0B1F30; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #ffffff; line-height: 1.6; }
        .container { background-color: #132A3A; max-width: 600px; margin: 40px auto; padding: 30px; border: 1px solid #00BFFF; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.3); }
        .logo { display: block; margin: 0 auto 20px; max-width: 150px; height: auto; }
        h1 { font-size: 1.8rem; text-align: center; color: #00BFFF; margin-bottom: 20px; }
        p { font-size: 1rem; margin: 20px 0; color: #ffffff; }
        .verification-code { background-color: #00BFFF; color: #0B1F30; font-size: 1.5rem; font-weight: bold; text-align: center; padding: 15px; margin: 20px 0; border-radius: 8px; letter-spacing: 3px; }
        .action { display: block; margin: 24px auto; padding: 14px 20px; border-radius: 8px; background: #00BFFF; color: #071621 !important; font-weight: 700; text-align: center; text-decoration: none; }
        .footer { text-align: center; padding: 20px 0; font-size: .9rem; color: #ffffff; }
        @media (max-width: 600px) { .container { margin: 20px; padding: 20px; } h1 { font-size: 1.5rem; } p { font-size: .9rem; } .verification-code { font-size: 1.2rem; padding: 10px; } }
    </style>
</head>
<body>
    <div class="container">
        <img src="https://petertecnet.com.br/petertecnetlogo.png" alt="Peter Tecnet" class="logo" />
        <h1>Olá {{ $userName }},</h1>
        <p>Recebemos um pedido para redefinir a senha da sua conta Peter Tecnet. Se você não solicitou esta alteração, ignore este e-mail.</p>
        <p>Use o código abaixo na página segura de redefinição:</p>
        <div class="verification-code">{{ $code }}</div>
        <a href="{{ $resetUrl }}" class="action">Redefinir minha senha</a>
        <p>O código é válido por 10 minutos. A página solicitará o código, a nova senha e a confirmação da nova senha.</p>
        <p>Não compartilhe este código com ninguém.</p>
    </div>
    <div class="footer">© {{ date('Y') }} Peter Tecnet. Todos os direitos reservados.</div>
</body>
</html>
