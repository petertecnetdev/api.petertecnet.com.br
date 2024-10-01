<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email de Boas-Vindas - Peter Tecnet</title>
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

        .user-info {
            font-size: 16px;
            color: rgb(1, 29, 45);
            margin-bottom: 10px;
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: 5px;
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
    <h1>Bem-vindo ao Peter Tecnet!</h1>
    <p>Olá {{ $user->first_name }},</p>
    <p>Estamos muito felizes em tê-lo(a) como parte da nossa comunidade! Abaixo estão suas credenciais de acesso:</p>
    <div class="user-info">
        <strong>E-mail:</strong> {{ $user->email }}
    </div>
    <div class="user-info">
        <strong>Senha:</strong> {{ $password }}
    </div>
    <div class="user-info">
        <strong>Código de verificação do e-mail:</strong> {{ $verificationCode }}
    </div>
    <p>Por favor, faça login no sistema usando essas credenciais e não se esqueça de alterar sua senha assim que possível.</p>
    <p>Não se esqueça de confirmar seu e-mail usando o código de verificação para ter acesso a todas as funcionalidades da Peter Tecnet.</p>
    <p>A Peter Tecnet é uma fábrica de soluções tecnológicas, comprometida em desenvolver aplicativos móveis e web que agregam valor à sociedade, resolvendo problemas reais e gerando resultados positivos.</p>
</div>

<div class="footer">
    <p>© {{ date('Y') }} Peter Tecnet. Todos os direitos reservados.</p>
</div>
</body>
</html>
