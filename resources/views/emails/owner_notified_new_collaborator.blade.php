<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novo colaborador adicionado</title>
    <style>
        body, html { margin: 0; padding: 0; }
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
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .container p {
            font-size: 1rem;
            margin: 20px 0;
            text-align: justify;
        }
        .footer {
            text-align: center;
            padding: 20px 0;
            font-size: 0.9rem;
        }
        .logo {
            display: block;
            margin: 0 auto 20px auto;
            max-width: 150px;
            height: auto;
        }
        @media (max-width: 600px) {
            .container { margin: 20px; padding: 20px; }
            .container p { font-size: 0.9rem; }
        }
    </style>
</head>

<body>
    <div class="container">
        <img src="https://petertecnet.com.br/logo.png" alt="Logo" class="logo" />

        <p>Olá,</p>
        <p>
            O usuário <strong>{{ $collaboratorName }}</strong> ({{ $collaboratorEmail }}) foi adicionado como colaborador no estabelecimento <strong>{{ $establishmentName }}</strong> com a função <strong>{{ $role }}</strong>.
        </p>
        <p>
            Você pode gerenciar as permissões e visualizar a lista completa de colaboradores no painel do estabelecimento.
        </p>
        <p>
            Se precisar ajustar funções ou remover o colaborador, acesse seu painel administrativo.
        </p>
    </div>
    <div class="footer">
        <p>© {{ date('Y') }} Todos os direitos reservados.</p>
    </div>
</body>

</html>
