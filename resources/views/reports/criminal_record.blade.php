<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Antecedentes Criminais</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; }
        .header { background-color: {{ $primaryColor }}; color: #fff; padding: 10px; text-align: center; }
        .section { margin: 20px; }
        .sub-header { background-color: {{ $secundaryColor }}; color: #fff; padding: 5px; }
        .info { margin: 10px 0; }
        img { max-width: 100%; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Relatório de Antecedentes Criminais</h1>
    </div>

    <div class="section">
        <div class="sub-header">
            <h2>Dados Pessoais</h2>
        </div>
        <div class="info">
            <strong>Nome:</strong> {{ $person['name'] }} <br>
            <strong>ID:</strong> {{ $person['id'] }} <br>
            <strong>Data de Criação:</strong> {{ $person['createdAt'] }}
        </div>
    </div>

    <div class="section">
        <div class="sub-header">
            <h2>Dados do Grupo</h2>
        </div>
        <div class="info">
            <strong>Nome:</strong> {{ $group['name'] }} <br>
            <strong>CNPJ:</strong> {{ $group['cnpj'] }} <br>
            <strong>ID:</strong> {{ $group['id'] }}
        </div>
    </div>

    <div class="section">
        <div class="sub-header">
            <h2>QR Code</h2>
        </div>
        <div class="info">
            <img src="data:image/png;base64,{{ $photosBase64['qrCode'] }}" alt="QR Code" style="width:100px;height:100px;"> <br>
            <strong>Link QR:</strong> <a href="{{ $qrPage }}">{{ $qrPage }}</a>
        </div>
    </div>

    <div class="section">
        <div class="sub-header">
            <h2>Documentos</h2>
        </div>
        <div class="info">
            <strong>Documento Frente ({{ $docs['documentFront']['type'] }}):</strong> <br>
            <img src="{{ $docs['documentFront']['photo'] }}" alt="Documento Frente" style="width:200px;"> <br>
            <strong>Documento Verso ({{ $docs['documentBack']['type'] }}):</strong> <br>
            <img src="{{ $docs['documentBack']['photo'] }}" alt="Documento Verso" style="width:200px;">
        </div>
    </div>
</body>
</html>
