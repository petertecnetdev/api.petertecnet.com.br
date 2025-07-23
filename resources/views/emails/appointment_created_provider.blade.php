<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Novo Agendamento - {{ $appointment->application->name }}</title>
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
    .content p,
    .content ul {
      font-size: 16px;
      line-height: 1.6;
      margin-bottom: 15px;
      color: #fff;
      text-align: left;
    }
    .content ul {
      list-style: none;
      padding: 0;
    }
    .content ul li {
      margin-bottom: 8px;
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
        <h2>Olá {{ $appointment->provider->first_name }},</h2>
        <p>
          Você recebeu um novo pedido de agendamento em <strong>{{ $appointment->entity->name }}</strong>
          pelo aplicativo <strong>{{ $appointment->application->name }}</strong>.
        </p>
        <p>Por favor, confirme ou cancele este agendamento o mais rápido possível:</p>
        <ul>
          <li>
            <strong>Data e horário:</strong>
            {{ $appointment->scheduled_at->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
          </li>
          <li>
            <strong>Serviços:</strong>
            <ul>
              @foreach($appointment->service_names as $service)
                <li>&ndash; {{ $service }}</li>
              @endforeach
            </ul>
          </li>
          <li>
            <strong>Cliente:</strong>
            {{ $appointment->info['name'] ?? $appointment->client->first_name }}
          </li>
          <li>
            <strong>Contato:</strong>
            {{ $appointment->info['phone'] ?? $appointment->client->phone }}
          </li>
          <li>
            <strong>Email:</strong>
            {{ $appointment->info['email'] ?? $appointment->client->email }}
          </li>
        </ul>
        <a href="{{ rtrim($appointment->application->url, '/') }}" class="btn-acessar">
          Ver e Gerenciar Agendamentos
        </a>
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
