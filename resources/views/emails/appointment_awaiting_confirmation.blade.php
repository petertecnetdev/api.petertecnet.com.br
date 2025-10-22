<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Confirmação de Agendamento</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; color: #333; }
        .container { background: #fff; border-radius: 10px; padding: 30px; margin: 40px auto; max-width: 600px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        h1 { color: #0baff5; font-size: 22px; margin-bottom: 20px; }
        p { font-size: 15px; line-height: 1.6; margin: 8px 0; }
        .highlight { font-weight: bold; color: #000; }
        .footer { margin-top: 30px; text-align: center; font-size: 13px; color: #777; border-top: 1px solid #eee; padding-top: 15px; }
        .btn { display: inline-block; background: #0baff5; color: #fff !important; text-decoration: none; padding: 10px 18px; border-radius: 6px; margin-top: 15px; }
        .btn:hover { background: #0098e0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>⏳ Seu agendamento está aguardando confirmação</h1>

        <p>Olá! Você recebeu um novo agendamento do estabelecimento <span class="highlight">{{ $establishmentName }}</span>.</p>

        <p><span class="highlight">💈 Atendente:</span> {{ $attendantName }}</p>
        <p><span class="highlight">🕓 Data e hora:</span> {{ $appointmentDate }}</p>
        <p><span class="highlight">✂️ Serviços:</span> {{ $services }}</p>

        <p>Confirme ou recuse este agendamento diretamente pelo aplicativo Rasoio.</p>

        <a href="{{ config('app.url') }}" class="btn">Confirmar Agendamento</a>

        <div class="footer">
            <p>Este é um e-mail automático. Não responda diretamente.</p>
            <p><strong>Rasoio • Gestão de Barbearias</strong></p>
        </div>
    </div>
</body>
</html>
