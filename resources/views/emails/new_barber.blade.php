<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email de Associação à Barbearia - Rasoio</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f7f7f7;
            margin: 0;
            padding: 0;
        }
        .email-wrapper {
            width: 100%;
            background-color: #f7f7f7;
            padding: 30px 0;
        }
        .email-container {
            max-width: 600px;
            margin: auto;
            background-color: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .header {
            background-color: #000; /* Cor principal: preto */
            padding: 20px;
            text-align: center;
            color: #fff; /* Cor secundária: branco */
        }
        .header img {
            width: 60px;
            margin-bottom: 10px;
        }
        .header h1 {
            font-size: 24px;
            margin: 0;
        }
        .content {
            padding: 30px 20px;
            color: #000; /* Texto em preto */
        }
        .content h2 {
            font-size: 22px;
            margin-bottom: 15px;
            text-align: center;
            color: #000;
        }
        .content p {
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 15px;
            color: #000;
        }
        .btn-acessar {
            display: block;
            width: fit-content;
            margin: 20px auto;
            text-decoration: none;
            background-color: #ffd700; /* Cor alternativa: amarelo */
            color: #000;
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: bold;
            box-shadow: 0 3px 8px rgba(0,0,0,0.15);
            transition: background-color 0.3s ease;
        }
        .btn-acessar:hover {
            background-color: #e6c200;
        }
        .barbershop-logo {
            display: block;
            margin: 15px auto;
            width: 80px;
            opacity: 0.9;
        }
        .footer {
            background-color: #fff; /* Cor secundária: branco */
            text-align: center;
            padding: 15px 10px;
            font-size: 14px;
            color: #000;
            border-top: 1px solid #ddd;
        }
        .footer img {
            width: 30px;
            vertical-align: middle;
        }
        @media(max-width: 600px) {
            .content {
                padding: 20px 15px;
            }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">
            <!-- Header -->
            <div class="header">
                <img src="{{ asset($barbershop->logo) }}" alt="Logo {{ $barbershop->name }}">
                <h1>{{ $barbershop->name }}</h1>
            </div>
            <!-- Content -->
            <div class="content">
                <h2>Olá {{ $barber->first_name }},</h2>
                <p>Você foi associado à barbearia <strong>{{ $barbershop->name }}</strong> e agora poderá acessar uma série de funcionalidades, como cadastrar serviços, gerenciar comissões, acompanhar agendamentos e visualizar avaliações.</p>
                <p>A Rasoio é um aplicativo inovador desenvolvido pela Peter Tecnet, projetado para facilitar a gestão da sua barbearia e aprimorar a experiência dos clientes.</p>
                <p>Estamos muito felizes em tê-lo(a) conosco. Faça parte da nossa equipe e otimize sua rotina com as nossas ferramentas.</p>
                <a href="https://rasoio.petertecnet.com.br" class="btn-acessar">Acessar o RASOIO</a>
                <div class="text-center">
                    <img src="https://rasoio.petertecnet.com.br/static/media/logo.52a59f87256ecf830ca0.gif" alt="Logo da Rasoio" style="max-width: 150px;">
                </div>
            </div>
            <!-- Footer -->
            <div class="footer">
                <p>© {{ date('Y') }} Peter Tecnet. <img src="https://petertecnet.com.br/images/peterlogo.png" alt="Logo Peter Tecnet"> Todos os direitos reservados.</p>
            </div>
        </div>
    </div>
</body>
</html>
