<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Novo Agendamento em Seu Estabelecimento</title>
  <style>
    body, html {
      margin: 0;
      padding: 0;
      width: 100%;
      background-color: #0B1F30;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      color: #ffffff;
      -webkit-text-size-adjust: none;
    }

    .wrapper {
      width: 100%;
      table-layout: fixed;
      background-color: #0B1F30;
      padding: 20px 0;
    }

    .container {
      background-color: #132A3A;
      max-width: 600px;
      margin: 0 auto;
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid #00BFFF;
      box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    }

    .header {
      background: linear-gradient(135deg, #0B1F30, #00BFFF);
      text-align: center;
      padding: 35px 20px;
    }

    .logo {
      max-width: 140px;
      height: auto;
      margin-bottom: 10px;
    }

    h1 {
      color: #ffffff;
      font-size: 1.8rem;
      margin: 10px 0 0;
    }

    .content {
      padding: 30px 25px;
    }

    .content p {
      margin: 16px 0;
      font-size: 1rem;
      line-height: 1.7;
      color: #e9e9e9;
    }

    .appointment-info {
      background-color: rgba(0, 191, 255, 0.1);
      border-left: 4px solid #00BFFF;
      padding: 15px 20px;
      border-radius: 8px;
      margin: 25px 0;
    }

    .appointment-info p {
      margin: 8px 0;
      color: #cce7ff;
      font-size: 0.95rem;
    }

    .appointment-info strong {
      color: #00BFFF;
      font-weight: 600;
    }

    .button {
      display: block;
      width: fit-content;
      background-color: #00BFFF;
      color: #0B1F30;
      text-decoration: none;
      font-weight: bold;
      padding: 12px 24px;
      border-radius: 6px;
      margin: 30px auto 10px;
      text-align: center;
      transition: all 0.3s ease;
    }

    .button:hover {
      background-color: #03A9F4;
    }

    .footer {
      background-color: #0B1F30;
      text-align: center;
      padding: 20px;
      font-size: 0.9rem;
      color: #b0c7d6;
    }

    @media (max-width: 600px) {
      .content {
        padding: 20px 15px;
      }

      h1 {
        font-size: 1.5rem;
      }

      .content p {
        font-size: 0.95rem;
      }

      .appointment-info {
        padding: 12px 15px;
      }

      .button {
        width: 100%;
        padding: 14px 0;
      }
    }
  </style>
</head>

<body>
  <div class="wrapper">
    <div class="container">
      <div class="header">
        <img src="https://petertecnet.com.br/logo.png" alt="Logo Peter Tecnet" class="logo" />
        <h1>📅 Novo Agendamento em Seu Estabelecimento</h1>
      </div>

      <div class="content">
        <p>Olá,</p>
        <p>
          Um novo agendamento foi realizado para o seu estabelecimento <strong>{{ $establishment }}</strong> através de uma de nossas plataformas.
          Confira as informações do agendamento abaixo:
        </p>

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
    </div>
  </div>
</body>
</html>
