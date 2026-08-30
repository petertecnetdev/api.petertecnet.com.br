<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Convite de Acesso</title>
    <style>
        body,
        html {
            margin: 0;
            padding: 0;
        }

        body {
            background-color: #0B1F30;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #ffffff;
            line-height: 1.6;
        }

        .container {
            background-color: #132A3A;
            max-width: 600px;
            margin: 40px auto;
            padding: 30px;
            border: 1px solid #00BFFF;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .logo {
            display: block;
            margin: 0 auto 20px auto;
            max-width: 150px;
            height: auto;
        }

        h1 {
            font-size: 1.8rem;
            text-align: center;
            color: #00BFFF;
            margin-bottom: 20px;
        }

        p {
            font-size: 1rem;
            margin: 20px 0;
            text-align: justify;
            color: #ffffff;
        }

        .verification-code {
            background-color: #00BFFF;
            color: #0B1F30;
            font-size: 1.5rem;
            font-weight: bold;
            text-align: center;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
            letter-spacing: 3px;
        }

        .action { display: block; margin: 24px auto; padding: 14px 20px; border-radius: 8px; background: #00BFFF; color: #071621 !important; font-weight: 700; text-align: center; text-decoration: none; }

        .app-url { overflow-wrap: anywhere; color: #72dcff; text-align: center; }

        .footer {
            text-align: center;
            padding: 20px 0;
            font-size: 0.9rem;
            color: #ffffff;
        }

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

            .logo {
                max-width: 130px;
            }
        }
    </style>
</head>

<body>

    <div class="container">
        <img src="https://petertecnet.com.br/logo.png" alt="Logo Peter Tecnet" class="logo" />

        <h1>Olá {{ $user->first_name }},</h1>

        <p>
            Você foi convidado para acessar o aplicativo <strong>{{ $appName }}</strong>,
            parte do ecossistema da Peter Tecnet — uma fábrica de soluções tecnológicas que desenvolve sistemas,
            aplicativos móveis e plataformas digitais que geram valor para pessoas e empresas.
        </p>

        <p>
            Para concluir seu acesso ao sistema <strong>{{ $appName }}</strong>, utilize o código abaixo.  
            Ele é necessário para ativar sua conta e criar sua senha de acesso.
        </p>

        <div class="verification-code">{{ $code }}</div>

        <a href="{{ $activationUrl }}" class="action">Criar minha senha e ativar conta</a>

        <p>
            Endereço oficial da aplicação:
        </p>
        <p class="app-url"><a href="{{ $appUrl }}" style="color:#72dcff">{{ $appUrl }}</a></p>

        <p>
            Abra o botão acima, confirme seu e-mail e defina uma senha pessoal. O convite expira em 24 horas.
            Se você não reconhece este convite, ignore esta mensagem e não compartilhe o código.
        </p>
    </div>

    <div class="footer">
        <p>© {{ date('Y') }} Peter Tecnet. Todos os direitos reservados.</p>
    </div>

</body>

</html>
