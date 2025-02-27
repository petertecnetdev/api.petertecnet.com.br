<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peter Tecnet - API</title>
    <meta name="description"
        content="A Peter Tecnet oferece solu��es de software inovadoras para diversos segmentos, como gerenciamento de barbearias, cl�nicas m�dicas, advocacia e gest�o de eventos. Tamb�m fornecemos uma API robusta para a cria��o de aplicativos sob medida ou personalizados conforme suas necessidades." />
    <meta name="keywords"
        content="Peter Tecnet, solu��es de software, gerenciamento de barbearia, software para cl�nicas, advocacia, gest�o de eventos, API, aplicativos personalizados, transforma��o digital, aplica��es empresariais" />
    <link rel="icon" href="/image/icon.png" type="image/png">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #000;
            font-family: Arial, sans-serif;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .content {
            text-align: center;
            color: white;
            padding: 20px;
            max-width: 600px;
        }

        .logo {
            max-width: 100%;
            height: auto;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }

        .welcome-message {
            font-size: 1.5rem;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
                opacity: 1;
            }

            50% {
                transform: scale(1.05);
                opacity: 0.8;
            }

            100% {
                transform: scale(1);
                opacity: 1;
            }
        }
    </style>
</head>

<body>
    <div class="content">
<<<<<<< HEAD
        <img src="/images/peterlogo.png" alt="Logo Peter Tecnet" class="logo">
=======
        <img src="images/peterlogo.png" alt="Logo Peter Tecnet" class="logo">
>>>>>>> ea70310b7222998c2b5b1dc816646a36b28addf7
        <p class="welcome-message">Peter Tecnet</p>
   Env     <p class="text-danger text-uppercase h4"><?php echo e(config('app.env')); ?></p>
    </div>
</body>

</html><?php /**PATH /var/www/staging.api.petertecnet.com.br/resources/views/welcome.blade.php ENDPATH**/ ?>