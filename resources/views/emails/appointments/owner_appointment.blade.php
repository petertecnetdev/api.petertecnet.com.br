<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novo Agendamento Recebido</title>
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
            margin: 15px 0;
            text-align: justify;
            color: #ffffff;
        }

        .appointment-info {
            background-color: #00BFFF;
            color: #0B1F30;
            font-size: 1rem;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .appointment-info strong {
            display: inline-block;
            width: 140px;
            color: #0B1F30;
        }

        .button {
            display: block;
            width: fit-content;
            background-color: #00BFFF;
            color: #0B1F30;
            text-decoration: none;
            font-weight: bold;
            padding: 10px 18px;
            border-radius: 8px;
            margin: 20px auto;
            text-align: center;
        }

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

            .appointment-info {
                font-size: 0.9rem;
                padding: 10px;
            }

            .button {
                font-size: 0.9rem;
                padding: 8px 14px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <img src="https://petertecnet.com.br/logo.png" alt="Logo Peter Tecnet" class="logo" />
        <h1>📅 Novo Agendamento em Seu Estabelecimento</h1>

        <p>Olá,</p>
        <p>Um novo agendamento foi realizado para o seu estabelecimento em uma de nossas plataformas. Confira as informações do agendamento abaixo:</p>

        <div class="appointment-info">
            <p><strong>Cliente:</strong> {{ $customerName }}</p>
            <p><strong>Colaborador:</strong> {{ $attendantName }}</p>
            <p><strong>Serviços:</strong> {{ $services }}</p>
            <p><strong>Data e Hora:</strong> {{ $date }}</p>
            <p><strong>Estabelecimento:</strong> {{ $establishment }}</p>
        </div>

        @if($appUrl)
        <a href="{{ $appUrl }}" class="button">Ver no Sistema</a>
        @endif

        <p>
            Este é um aviso automático informando que um cliente realizou um novo agendamento em seu estabelecimento.
            Certifique-se de que sua equipe esteja preparada para o atendimento no horário indicado.
        </p>
    </div>

    <div class="footer">
        <p>© {{ date('Y') }} Peter Tecnet. Todos os direitos reservados.</p>
    </div>
</body>

</html>
