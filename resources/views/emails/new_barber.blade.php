<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email de Associação à Barbearia - Rasoio</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background-color: rgb(0, 0, 0);
      margin: 0;
      padding: 0;
      color: #fff;
    }
    .email-wrapper {
      width: 100%;
      background-color: rgb(0, 0, 0);
      color: #fff;
      padding: 30px 0;
      border: 1px solid #07f7ff;
    }
    .email-container {
      max-width: 600px;
      margin: auto;
      background-color: #000;
      border-radius: 15px;
      overflow: hidden;
      box-shadow: 0 4px 12px rgba(0,0,0,0.5);
    }
    .header {
      background-color: #000;
      padding: 20px;
      text-align: center;
      color: #fff;
      border-bottom: 1px solid #07f7ff;
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
      background-color: #000;
      padding: 30px 20px;
      text-align: center;
    }
    .content h2 {
      font-size: 22px;
      margin-bottom: 15px;
      color: #fff;
    }
    .content p {
      font-size: 16px;
      line-height: 1.6;
      margin-bottom: 15px;
      color: #fff;
    }
    .btn-acessar {
      display: inline-block;
      text-decoration: none;
      background-color: rgb(0, 0, 0);
      color: #fff;
      padding: 12px 30px;
      border-radius: 30px;
      font-weight: bold;
      margin-top: 20px;
      transition: background-color 0.3s ease;
      border: 1px solid #07f7ff;
    }
    .btn-acessar:hover {
      background-color: #07f7ff;
      color: #000;
    }
    .barbershop-logo {
      display: block;
      margin: 15px auto;
      width: 80px;
      opacity: 0.9;
    }
    .footer {
      background-color: #000;
      color: #fff;
      text-align: center;
      padding: 15px 10px;
      font-size: 14px;
      border-top: 1px solid #07f7ff;
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
      <!-- Conteúdo -->
      <div class="content">
        <h2>Olá {{ $barber->first_name }},</h2>
        <p>
          Você foi associado à barbearia <strong>{{ $barbershop->name }}</strong> e agora poderá acessar diversas funcionalidades,
          como cadastrar serviços, gerenciar comissões, acompanhar agendamentos e visualizar avaliações.
        </p>
        <p>
          A Rasoio é um aplicativo inovador desenvolvido pela Peter Tecnet, projetado para facilitar a gestão da sua barbearia
          e aprimorar a experiência dos clientes.
        </p>
        <p>
          Estamos muito felizes em tê-lo(a) conosco. Faça parte da nossa equipe e otimize sua rotina com as nossas ferramentas.
        </p>
        <a href="https://rasoio.petertecnet.com.br" class="btn-acessar">Acessar o RASOIO</a>
        <div class="text-center">
          <img src="https://rasoio.petertecnet.com.br/images/logo.png" alt="Logo da Rasoio" style="max-width: 150px; margin-top: 20px;">
        </div>
      </div>
    </div>
  </div>
</body>
</html>
