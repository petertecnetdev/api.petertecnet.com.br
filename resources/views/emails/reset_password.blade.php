<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email de Recuperação de Senha - Peter Tecnet</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f4f4f4;
            color: rgb(0, 116, 138);
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 600px;
            margin: 20px auto;
            padding: 20px;
            background-color: #fff;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: rgb(0, 116, 138);
            text-align: center;
            font-size: 28px;
            margin-bottom: 20px;
        }

        p {
            margin-bottom: 15px;
            font-size: 16px;
            line-height: 1.5;
        }

        .verification-code {
            font-size: 24px;
            font-weight: bold;
            color: rgb(1, 29, 45);
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: 5px;
            text-align: center;
            margin: 20px 0;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            color: #777;
            font-size: 14px;
        }

        .logo {
            display: block;
            margin: 0 auto 20px;
            width: 150px; /* Ajuste o tamanho da logo */
        }
    </style>
</head>
<body>
<div class="container">
    <img src="https://petertecnet.com.br/peterlogo.png" alt="Logo Peter Tecnet" class="logo" />
    <h1>Olá {{ $user->name }},</h1>
    <p>Recebemos um pedido para redefinir sua senha. Se você não solicitou esta alteração, pode ignorar este e-mail.</p>
    <p>O código de redefinição de senha abaixo é necessário para que você possa criar uma nova senha:</p>
    <div class="verification-code">{{ $code }}</div>
    <p>Por favor, utilize este código na página de redefinição de senha para continuar.</p>
    <p>Este código é válido por 10 minutos. Certifique-se de utilizá-lo a tempo!</p>
    <p>Estamos aqui para ajudar caso você tenha qualquer dúvida.</p>
</div>

<div class="footer">
    <p>© {{ date('Y') }} Peter Tecnet. Todos os direitos reservados.</p>
</div>
</body>
</html>
