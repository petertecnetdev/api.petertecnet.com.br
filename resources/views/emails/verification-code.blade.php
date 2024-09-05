<!-- resources/views/emails/verification-code.blade.php -->

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

        .verification-code {
            font-size: 24px;
            font-weight: bold;
            color: #e44d26;
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
        <h1>Olá {{ $user->name }},</h1>
        <p>Obrigado por se registrar na Cutinapp. Seu código de verificação é:</p>
        <div class="verification-code">{{ $verificationCode }}</div>
        <p>Por favor, use este código para concluir seu registro.</p>
    </div>
    <div class="footer">
        <p>© {{ date('Y') }} Cutinapp. Todos os direitos reservados.</p>
    </div>
</body>
</html>
