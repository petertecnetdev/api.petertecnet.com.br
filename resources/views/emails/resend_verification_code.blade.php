<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email de Verificação - Peter Tecnet</title>
  <style>
    /* Reset básico */
    body, html {
      margin: 0;
      padding: 0;
    }

    /* Estilo Global */
    body {
      background-color: #0B1F30; /* Fundo escuro, inspirado na cor predominante do logo */
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      color: #ffffff; /* Texto branco para contraste */
      line-height: 1.6;
    }

    /* Container Principal */
    .container {
      background-color: #132A3A; /* Tom intermediário entre o fundo e o destaque */
      max-width: 600px;
      margin: 40px auto;
      padding: 30px;
      border: 1px solid #00BFFF; /* Azul claro inspirado no triângulo do logo */
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    }

    /* Texto Inicial */
    .container p {
      font-size: 1rem;
      margin: 20px 0;
      text-align: justify;
      color: #ffffff;
    }

    /* Código de Verificação */
    .verification-code {
      background-color: #00BFFF; /* Azul claro */
      color: #0B1F30; /* Contraste forte */
      font-size: 1.5rem;
      font-weight: bold;
      text-align: center;
      padding: 15px;
      margin: 20px 0;
      border-radius: 8px;
    }

    /* Rodapé */
    .footer {
      text-align: center;
      padding: 20px 0;
      font-size: 0.9rem;
      color: #ffffff;
    }

    /* Responsividade */
    @media (max-width: 600px) {
      .container {
        margin: 20px;
        padding: 20px;
      }

      .container p {
        font-size: 0.9rem;
      }

      .verification-code {
        font-size: 1.2rem;
        padding: 10px;
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <p>Olá {{ $user->first_name }},</p>
    <p>
      Obrigado por se registrar em um de nossos aplicativos! A Peter Tecnet é uma fábrica de soluções tecnológicas, 
      desenvolvendo aplicativos móveis e web que têm como foco agregar valor à sociedade, resolver problemas reais 
      e gerar resultados positivos.
    </p>
    <p>
      O código de verificação abaixo é fundamental para ativar sua conta. Esse cadastro é válido para todos os aplicativos 
      da Peter Tecnet, permitindo que você tenha acesso completo a nossas plataformas e recursos.
    </p>
    <div class="verification-code">{{ $verificationCode }}</div>
    <p>
      Por favor, utilize este código para concluir seu registro e acessar todas as funcionalidades que temos a oferecer.
    </p>
    <p>
      Estamos felizes em tê-lo como parte da nossa comunidade!
    </p>
  </div>
  <div class="footer">
    <p>© {{ date('Y') }} Peter Tecnet. Todos os direitos reservados.</p>
  </div>
</body>
</html>
