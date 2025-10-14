<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bem-vindo ao Estabelecimento - Peter Tecnet</title>
    <style>
        body, html { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0B1F30; color: #fff; line-height: 1.6; }
        .container { background-color: #132A3A; max-width: 600px; margin: 40px auto; padding: 30px; border: 1px solid #00BFFF; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
        .logo { display: block; margin: 0 auto 20px auto; max-width: 150px; height: auto; }
        h1 { font-size: 1.8rem; text-align: center; color: #00BFFF; margin-bottom: 20px; }
        p { font-size: 1rem; margin: 20px 0; text-align: justify; color: #fff; }
        .verification-code { background-color: #00BFFF; color: #0B1F30; font-size: 1.5rem; font-weight: bold; text-align: center; padding: 15px; margin: 20px 0; border-radius: 8px; }
        .btn { display: inline-block; background-color: #00BFFF; color: #0B1F30; text-decoration: none; padding: 12px 20px; border-radius: 6px; font-weight: bold; }
        .footer { text-align: center; padding: 20px 0; font-size: 0.9rem; color: #fff; }
        @media (max-width: 600px) { .container { margin: 20px; padding: 20px; } h1 { font-size: 1.5rem; } p { font-size: 0.9rem; } .verification-code { font-size: 1.2rem; padding: 10px; } }
    </style>
</head>

<body>
    <div class="container">
        <img src="https://petertecnet.com.br/logo.png" alt="Logo Peter Tecnet" class="logo" />
        <h1>Olá {{ $userName }},</h1>
        <p>Seja bem-vindo! Você foi associado a um novo estabelecimento. Para concluir seu cadastro e criar sua senha de acesso, clique no botão abaixo e insira o código enviado:</p>
        <div class="verification-code">{{ $code }}</div>
        <p><a href="{{ $link }}" class="btn">Criar Minha Senha</a></p>
        <p>O código é válido por 10 minutos. Caso não tenha solicitado este cadastro, pode ignorar este e-mail.</p>
        <p>Estamos à disposição para qualquer dúvida!</p>
    </div>
    <div class="footer">
        <p>© {{ date('Y') }} Peter Tecnet. Todos os direitos reservados.</p>
    </div>
</body>

</html>
