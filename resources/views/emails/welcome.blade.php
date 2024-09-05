<!-- resources/views/emails/welcome.blade.php -->

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

        .user-info {
            font-size: 16px;
            color: #333;
            margin-bottom: 10px;
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
        <h1>Bem-vindo ao Cutinapp!</h1>
        <p>Olá {{ $user->first_name }},</p>
        <p>Bem-vindo ao Cutinapp! Abaixo estão suas credenciais de acesso:</p>
        <div class="user-info">
            <strong>E-mail:</strong> {{ $user->email }}
        </div>
        <div class="user-info">
            <strong>Senha:</strong> {{ $password }}
        </div>
        <div class="user-info">
            <strong>Codigo para verificação do email:</strong> {{ $verificationCode }}
        </div>
        <p>Por favor, faça login no sistema usando essas credenciais e não se esqueça de alterar sua senha assim que possível.</p>
        <p>Não se esqueça de confirmar seu email também com o codigo de verificação para ter acesso as funcionalidades da Cutinapp</p>
    </div>
    <div class="footer">
        <p>© {{ date('Y') }} Cutinapp. Todos os direitos reservados.</p>
    </div>
</body>
</html>
