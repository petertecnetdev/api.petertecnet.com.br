<!DOCTYPE html>
<html lang="pt">

<head>
    <meta charset="UTF-8">
    <title>Relatório de Antecedentes Criminais</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f8f9fa;
            color: #333;
        }

        .container {
            width: 90%;
            margin: auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            background-color: {{ $primaryColor }};
            color: #fff;
            text-align: center;
            padding: 15px;
            font-size: 22px;
            font-weight: bold;
            border-radius: 8px 8px 0 0;
        }

        .section {
            padding: 15px;
            margin-bottom: 15px;
            border-bottom: 2px solid {{ $secundaryColor }};
            background: #f1f1f1;
            border-radius: 5px;
        }

        .sub-header {
            background-color: {{ $secundaryColor }};
            color: #fff;
            padding: 10px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 5px;
            margin-bottom: 10px;
        }

        .info {
            margin: 10px 0;
            font-size: 14px;
            padding: 10px;
            background: #fff;
            border-radius: 5px;
        }

        .info strong {
            color: {{ $primaryColor }};
        }

        img {
            max-width: 100%;
            display: block;
            margin: 10px auto;
            border-radius: 5px;
            border: 1px solid #ddd;
        }

        .qr-container {
            text-align: center;
            padding: 15px;
        }

        .qr-container img {
            width: 150px;
            height: 150px;
            border: 3px solid {{ $primaryColor }};
            padding: 5px;
        }

        .link {
            display: inline-block;
            margin-top: 10px;
            font-size: 14px;
            color: {{ $primaryColor }};
            font-weight: bold;
            text-decoration: none;
        }

        .small-text {
            font-size: 12px;
            color: #666;
        }

        .footer {
            text-align: center;
            font-size: 10px;
            color: #888;
            margin-top: 20px;
            padding: 10px;
            border-top: 1px solid #ccc;
        }
    </style>
</head>

<body>

    <div class="container">

        <div class="header">
            Relatório de Antecedentes Criminais
        </div>

        <div class="section">
            <div class="sub-header">Dados Pessoais</div>
            <div class="info">
                <strong>Nome:</strong> {{ $person['name'] }} <br>
                <strong>ID:</strong> {{ $person['id'] }} <br>
                <strong>Data de Criação:</strong> {{ date('d/m/Y H:i', strtotime($person['createdAt'])) }} <br>
                <strong>Última Modificação:</strong> {{ date('d/m/Y H:i', strtotime($person['modifiedAt'])) }}
            </div>
        </div>

        <div class="section">
            <div class="sub-header">Dados do Grupo</div>
            <div class="info">
                <strong>Nome:</strong> {{ $group['name'] }} <br>
                <strong>CNPJ:</strong> {{ $group['cnpj'] }} <br>
                <strong>ID:</strong> {{ $group['id'] }} <br>
                <strong>Data de Criação:</strong> {{ date('d/m/Y H:i', strtotime($group['createdAt'])) }} <br>
                <strong>Última Modificação:</strong> {{ date('d/m/Y H:i', strtotime($group['modifiedAt'])) }}
            </div>
        </div>

        <div class="section qr-container">
            <div class="sub-header">QR Code</div>
            <img src="data:image/png;base64,{{ $photosBase64['qrCode'] }}" alt="QR Code">
            <br>
            <a class="link" href="{{ $qrPage }}" target="_blank">Escaneie ou clique aqui para acessar</a>
        </div>

        <div class="section">
            <div class="sub-header">Documentos</div>
            <div class="info">
                <strong>Documento Frente ({{ $docs['documentFront']['type'] }}):</strong> <br>
                <img src="{{ $docs['documentFront']['photo'] }}" alt="Documento Frente" style="width:250px;"> <br>
                <span class="small-text">Confiança: {{ $docs['documentFront']['score'] * 100 }}%</span>
                <br><br>
                <strong>Documento Verso ({{ $docs['documentBack']['type'] }}):</strong> <br>
                <img src="{{ $docs['documentBack']['photo'] }}" alt="Documento Verso" style="width:250px;">
                <br>
                <span class="small-text">Confiança: {{ $docs['documentBack']['score'] * 100 }}%</span>
            </div>
        </div>

        <div class="footer">
            <p><strong>Aviso:</strong> Este relatório pode conter dados fictícios, pois o sistema ainda está em fase de
                testes.
                Nenhuma informação aqui exibida deve ser usada como referência oficial.</p>
        </div>

    </div>

</body>

</html>
