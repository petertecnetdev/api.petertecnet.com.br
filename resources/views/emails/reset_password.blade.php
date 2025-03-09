<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email de Recuperação de Senha - Peter Tecnet</title>
    <style>
        /* Reset básico */
        body,
        html {
            margin: 0;
            padding: 0;
        }

        /* Estilo Global */
        body {
            background-color: #0B1F30;
            /* Fundo escuro, inspirado na cor predominante do logo */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #ffffff;
            /* Texto branco para contraste */
            line-height: 1.6;
        }

        /* Container Principal */
        .container {
            background-color: #132A3A;
            /* Tom intermediário entre o fundo e o destaque */
            max-width: 600px;
            margin: 40px auto;
            padding: 30px;
            border: 1px solid #00BFFF;
            /* Azul claro inspirado no triângulo do logo */
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        /* Logo */
        .logo {
            display: block;
            margin: 0 auto 20px auto;
            max-width: 150px;
            height: auto;
        }

        /* Cabeçalho */
        h1 {
            font-size: 1.8rem;
            text-align: center;
            color: #00BFFF;
            /* Destaque principal */
            margin-bottom: 20px;
        }

        /* Parágrafos */
        p {
            font-size: 1rem;
            margin: 20px 0;
            text-align: justify;
            color: #ffffff;
        }

        /* Código de Verificação */
        .verification-code {
            background-color: #00BFFF;
            /* Azul claro */
            color: #0B1F30;
            /* Contraste forte */
            font-size: 1.5rem;
            font-weight: bold;
            text-align: center;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
        }

        /* Rodapé */
        .footer {
            text-align: center;
            padding: 20px 0;
            font-size: 0.9rem;
            color: #ffffff;
        }

        /* Responsividade */
        @media (max-width: 600px) {
            .container {
                margin: 20px;
                padding: 20px;
            }

            h1 {
                font-size: 1.5rem;
            }

            p {
                font-size: 0.9rem;
            }

            .verification-code {
                font-size: 1.2rem;
                padding: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <img src="https://petertecnet.com.br/logo.png" alt="Logo Peter Tecnet" class="logo" />

        <h1>Olá {{ $user->first_name }},</h1>
        <p>
            Recebemos um pedido para redefinir sua senha. Se você não solicitou esta alteração, pode ignorar este
            e-mail.
        </p>
        <p>
            O código de redefinição de senha abaixo é necessário para que você possa criar uma nova senha:
        </p>
        <div class="verification-code">{{ $code }}</div>
        <p>
            Por favor, utilize este código na página de redefinição de senha para continuar.
        </p>
        <p>
            Este código é válido por 10 minutos. Certifique-se de utilizá-lo a tempo!
        </p>
        <p>
            Estamos aqui para ajudar caso você tenha qualquer dúvida.
        </p>
    </div>

    <div class="footer">
        <p>© {{ date('Y') }} Peter Tecnet. Todos os direitos reservados.</p>
    </div>
</body>

</html>