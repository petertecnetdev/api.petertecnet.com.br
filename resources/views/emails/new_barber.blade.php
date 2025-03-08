<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  
    <title>Email de Associação à Barbearia - Rasoio</title>
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
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #00748a;
            text-align: center;
            font-size: 26px;
            margin-bottom: 10px;
        }

        p {
            margin-bottom: 15px;
            font-size: 16px;
            line-height: 1.5;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            color: #777;
            font-size: 14px;
        }

        .peter-logo {
            width: 30px;
        }

        .rasoio-logo {
            width: 120px;
            display: block;
            margin: 0 auto;
        }

        .barbershop-logo {
            margin-top: 20px;
            display: block;
            margin-left: auto;
            margin-right: auto;
            width: 80px;
            opacity: 0.7;
        }

        .welcome-message {
            background-color: #e7f8f9;
            padding: 10px;
            border-radius: 5px;
            margin: 20px 0;
            text-align: center;
        }

        .thank-you {
            font-weight: bold;
            color: #00748a;
        }

        .link-button {
            display: inline-block;
            text-decoration: none;
            font-size: 18px;
            font-weight: bold;
            color: #fff;
            padding: 15px 30px;
            background-color: #00748a;
            border-radius: 30px;
            margin: 20px auto;
            text-align: center;
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
        }

        .link-button:hover {
            background-color: #005f6b;
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
            transform: translateY(-3px);
        }

        .link {
            font-size: 24px;
            font-weight: bold;
            color: rgb(1, 29, 45);
            padding: 10px;
            background-color: rgb(245, 245, 245);
            border-radius: 5px;
            text-align: center;
            margin: 20px 0;
        }
        .rasoio-logo {
            border-radius: 50%; /* Para um efeito de círculo */
            width: 50%; /* Tamanho responsivo */
            max-width: 200px; /* Limita o tamanho máximo */
            height: auto; /* Mantém a proporção da imagem */
        }
    </style>
</head>
<body>
<div class="container text-center">
    <div class="welcome-message">
        <p>Seja bem-vindo à barbearia <strong>{{ $barbershop->name }}</strong>!</p>
        <img src="{{ asset('images/'.$barbershop->logo) }}" alt="Logo {{ $barbershop->name }}" class="barbershop-logo" />
    </div>
    
    <h1>Olá {{ $barber->first_name }},</h1>
    <p>Você foi associado à barbearia <strong>{{ $barbershop->name }}</strong> e agora poderá cadastrar informações de serviços prestados, gerenciar comissões, ver agendamentos, visualizar avaliações e outras funcionalidades da Rasoio.</p>
    <p>A Rasoio é um aplicativo inovador desenvolvido pela Peter Tecnet, projetado para facilitar a gestão da sua barbearia e aprimorar a experiência dos clientes. Com a Rasoio, você terá acesso a uma série de ferramentas úteis que permitirão otimizar seus agendamentos, gerenciar clientes e muito mais.</p>
    
    <p>Estamos muito felizes em tê-lo(a) conosco. Você agora faz parte da nossa equipe e estamos ansiosos para vê-lo(a) em ação!</p>
  
    <div class="welcome-message">
         <a href="https://rasoio.petertecnet.com.br">
        <p>Clique aqui para acessar a <strong> RASOIO </strong>!</p>
        <img src="https://rasoio.petertecnet.com.br/static/media/logo.52a59f87256ecf830ca0.gif" alt="Logo da Rasoio" class="rasoio-logo" />
        </a>
    </div>
</div>

<div class="footer">
    <p>© {{ date('Y') }} Peter Tecnet. <img src="https://petertecnet.com.br/images/peterlogo.png" alt="Logo Peter Tecnet" class="peter-logo" /> Todos os direitos reservados.</p>
</div>
</body>
</html>
