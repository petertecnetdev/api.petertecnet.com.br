<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Agendamento Atualizado - {{ $appointment->application->name }}</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background-color: #000;
      margin: 0;
      padding: 0;
      color: #fff;
    }
    .email-wrapper {
      width: 100%;
      background-color: #000;
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
      border-bottom: 1px solid #07f7ff;
    }
    .header img {
      width: 60px;
      margin-bottom: 10px;
    }
    .header h1 {
      font-size: 24px;
      margin: 0;
      color: #fff;
    }
    .content {
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
      text-align: left;
    }
    .btn-acessar {
      display: inline-block;
      text-decoration: none;
      background-color: #000;
      color: #fff;
      padding: 12px 30px;
      border-radius: 30px;
      font-weight: bold;
      border: 1px solid #07f7ff;
      margin-top: 20px;
      transition: background-color 0.3s ease;
    }
    .btn-acessar:hover {
      background-color: #07f7ff;
      color: #000;
    }
    .footer {
      background-color: #000;
      text-align: center;
      padding: 15px 10px;
      font-size: 14px;
      border-top: 1px solid #07f7ff;
      color: #fff;
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
      <!-- Header com logo e nome da entidade -->
      <div class="header">
        <img src="{{ asset($appointment->entity->logo) }}" alt="Logo {{ $appointment->entity->name }}">
        <h1>{{ $appointment->entity->name }}</h1>
      </div>
      <!-- Conteúdo -->
      <div class="content">
        <h2>Olá {{ $appointment->info['name'] ?? $appointment->client->first_name }},</h2>

        @if($status === 'confirmed')
          <p>
            Boas notícias! Seu agendamento foi <strong>confirmado</strong> com 
            <strong>{{ $appointment->provider->first_name }}</strong> em 
            <strong>{{ $appointment->entity->name }}</strong> dia 
            <strong>{{ $appointment->scheduled_at->setTimezone('America/Sao_Paulo')->format('d/m/Y') }}</strong> às 
            <strong>{{ $appointment->scheduled_at->setTimezone('America/Sao_Paulo')->format('H:i') }}</strong>.
          </p>
          <p>Por favor, chegue 5 minutos antes.</p>
          <p>Chegue no horário para não impactar outros agendamentos.</p>
        @elseif($status === 'cancelled')
          <p>
            Seu agendamento em <strong>{{ $appointment->entity->name }}</strong> foi <strong>cancelado</strong>.  
            Não fique triste: você pode marcar outro horário ou escolher outro prestador.
          </p>
          <p>
            Acesse 
            <a href="{{ rtrim($appointment->application->url, '/') }}" class="btn-acessar">
              {{ rtrim($appointment->application->url, '/') }}
            </a> 
            para solicitar outro agendamento.
          </p>
        @endif

      </div>
      <!-- Footer com marca do aplicativo -->
      <div class="footer">
        {{ $appointment->application->name }} &nbsp;
        <img src="{{ asset($appointment->application->logo) }}" alt="{{ $appointment->application->name }}">
      </div>
    </div>
  </div>
</body>
</html>
