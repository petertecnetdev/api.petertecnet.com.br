<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email de Verificação - Peter Tecnet</title>
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
    <p>Obrigado por se registrar na Peter Tecnet! A Peter Tecnet é uma fábrica de soluções tecnológicas, comprometida em desenvolver aplicativos móveis e web que agregam valor à sociedade, resolvendo problemas reais e gerando resultados positivos.</p>
    <p>Seu código de verificação é essencial para ativar sua conta. Este código garante que você tenha acesso a todas as funcionalidades de nossos aplicativos, que são projetados para atender às suas necessidades.</p>
    <div class="verification-code">{{ $verificationCode }}</div>
    <p>Por favor, use este código para concluir seu registro e explorar tudo o que a Peter Tecnet tem a oferecer.</p>
</div>

<div class="footer">
    <p>© {{ date('Y') }} Peter Tecnet. Todos os direitos reservados.</p>
</div>
</body>
</html>
