<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Você foi adicionado como colaborador</title>
  <style>
    /* Reset básico */
    body, html {
      margin: 0;
      padding: 0;
    }

    /* Fonte principal */
    body {
      background-color: #001e2d; /* tom escuro do círculo de fundo */
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      color: #e1eaf0; /* cor de texto mais suave */
      line-height: 1.6;
    }

    /* Container central */
    .container {
      background: radial-gradient(circle at top left, #002a3f 0%, #001e2d 100%);
      max-width: 600px;
      margin: 40px auto;
      padding: 30px;
      border: 2px solid #00d2ff; /* azul-ciano forte do triângulo */
      border-radius: 12px;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5);
      position: relative;
      overflow: hidden;
    }

    /* Line decorativa em triângulo */
    .container::before {
      content: '';
      position: absolute;
      top: -60px;
      right: -60px;
      width: 200px;
      height: 200px;
      background: transparent;
      border-top: 4px solid #00d2ff;
      border-right: 4px solid #00d2ff;
      transform: rotate(45deg);
      opacity: 0.3;
    }

    /* Logo */
    .logo {
      display: block;
      margin: 0 auto 20px auto;
      width: 120px;
      height: auto;
    }

    /* Texto */
    .container p {
      font-size: 1rem;
      margin: 16px 0;
      text-align: justify;
    }

    /* Destaque do nome e cargo */
    .highlight {
      color: #00d2ff;
      font-weight: bold;
    }

    /* Rodapé */
    .footer {
      text-align: center;
      padding: 20px 0;
      font-size: 0.9rem;
      color: #a3bcd9;
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
    }
  </style>
</head>

<body>
  <div class="container">
    <img src="https://petertecnet.com.br/logo.png" alt="Logo" class="logo" />

    <p>Olá <span class="highlight">{{ $userName }}</span>,</p>
    <p>
      Você foi adicionado como colaborador no estabelecimento 
      <span class="highlight">{{ $establishmentName }}</span> com a função de 
      <span class="highlight">{{ $role }}</span>.
    </p>
    <p>
      Agora você possui acesso às funcionalidades de gerenciamento e pode ajudar nas operações diárias.
      Acesse o painel para visualizar suas permissões e começar a colaborar.
    </p>
    <p>
      Se tiver alguma dúvida, entre em contato com o proprietário do estabelecimento.
    </p>
  </div>
  <div class="footer">
    <p>© {{ date('Y') }} Todos os direitos reservados.</p>
  </div>
</body>

</html>
