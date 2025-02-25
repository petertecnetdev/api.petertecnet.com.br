<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email de Verificação - Peter Tecnet</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background: linear-gradient(to bottom, rgb(1, 29, 45), rgb(0, 116, 138));
            color: rgb(0, 116, 138);
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 600px;
            margin: 40px auto;
            padding: 20px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.2);
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
            background-color: rgb(245, 245, 245);
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

        @media (max-width: 600px) {
            h1 {
                font-size: 24px;
            }
            .verification-code {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <img src="https://petertecnet.com.br/peterlogo.png" alt="Logo Peter Tecnet" class="logo" />
        <h1>Olá {{ $user->name }},</h1>
        <p>Obrigado por se registrar em um de nossos aplicativos! A Peter Tecnet é uma fábrica de soluções tecnológicas, desenvolvendo aplicativos móveis e web que têm como foco agregar valor à sociedade, resolver problemas reais e gerar resultados positivos.</p>
        <p>O código de verificação abaixo é fundamental para ativar sua conta. Esse cadastro é válido para todos os aplicativos da Peter Tecnet, permitindo que você tenha acesso completo a nossas plataformas e recursos.</p>
        <div class="verification-code">{{ $verificationCode }}</div>
        <p>Por favor, utilize este código para concluir seu registro e acessar todas as funcionalidades que temos a oferecer.</p>
        <p>Estamos felizes em tê-lo como parte da nossa comunidade!</p>
    </div>
    <div class="footer">
        <p>© {{ date('Y') }} Peter Tecnet. Todos os direitos reservados.</p>
    </div>
</body>
</html>
