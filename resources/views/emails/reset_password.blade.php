<!-- resources/views/emails/reset_password.blade.php -->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f4f4f4;
            color: #333;
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
            color: #e44d26;
        }

        p {
            margin-bottom: 15px;
        }

        .reset-link {
            font-size: 18px;
            font-weight: bold;
            color: #e44d26;
            text-decoration: none;
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: 5px;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            color: #777;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Redefinição de Senha</h1>
    <p>Você está recebendo este e-mail porque uma solicitação de redefinição de senha foi feita para sua conta.</p>
    <p>Por favor, use o código abaixo para redefinir sua senha:</p>
    <h2>{{ $code }}</h2>
    <p>Se você não solicitou uma redefinição de senha, ignore este e-mail.</p>
    <p>Este código será invalidado em 10 minutos.</p>
</div>

    <div class="footer">
        <p>© {{ date('Y') }} Cutinapp. Todos os direitos reservados.</p>
    </div>
</body>
</html>
