<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Novo colaborador adicionado</title>
  <style>
    /* Reset básico */
    body, html {
      margin: 0;
      padding: 0;
    }

    /* Fonte e fundo */
    body {
      background-color: #001e2d; /* circulo escuro da logo */
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      color: #e1eaf0; /* off-white suave */
      line-height: 1.6;
    }

    /* Container principal */
    .container {
      background: radial-gradient(circle at top left, #002a3f 0%, #001e2d 100%);
      max-width: 600px;
      margin: 40px auto;
      padding: 30px;
      border: 2px solid #00d2ff; /* azul-ciano do triângulo */
      border-radius: 12px;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5);
      position: relative;
      overflow: hidden;
    }

    /* Elemento decorativo em forma de canto de triângulo */
    .container::before {
      content: '';
      position: absolute;
      bottom: -60px;
      left: -60px;
      width: 200px;
      height: 200px;
      border-bottom: 4px solid #00d2ff;
      border-left:   4px solid #00d2ff;
      transform: rotate(-45deg);
      opacity: 0.3;
    }

    /* Logo */
    .logo {
      display: block;
      margin: 0 auto 20px auto;
      width: 120px;
      height: auto;
    }

    /* Parágrafos */
    .container p {
      font-size: 1rem;
      margin: 16px 0;
      text-align: justify;
    }

    /* Destaques */
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

    <p>Olá,</p>
    <p>
      O usuário <span class="highlight">{{ $collaboratorName }}</span> 
      (<span class="highlight">{{ $collaboratorEmail }}</span>) foi adicionado como colaborador 
      no estabelecimento <span class="highlight">{{ $establishmentName }}</span> com a função 
      <span class="highlight">{{ $role }}</span>.
    </p>
    <p>
      Você pode gerenciar as permissões e visualizar a lista completa de colaboradores no painel do estabelecimento.
    </p>
    <p>
      Se precisar ajustar funções ou remover o colaborador, acesse seu painel administrativo.
    </p>
  </div>
  <div class="footer">
    <p>© {{ date('Y') }} Todos os direitos reservados.</p>
  </div>
</body>

</html>
