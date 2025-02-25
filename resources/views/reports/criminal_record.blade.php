<!DOCTYPE html>
<html lang="pt">

<head>
    <meta charset="UTF-8">
    <title>Relatório de Antecedentes Criminais</title>
    <style>
        @page {
            margin: 100px 25px 70px 25px;
        }

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
            /* Força quebra de página ao final, se necessário */
            page-break-after: always;
        }

        /* Elementos da capa */
        .cover {
            text-align: center;
            padding: 50px 20px;
        }

        .cover h1 {
            font-size: 32px;
            margin-top: 20px;
        }

        .cover h2 {
            font-size: 22px;
            margin: 10px 0;
        }

        .cover p {
            font-size: 14px;
            margin-top: 10px;
        }

        .cover .qr-container {
            margin-top: 20px;
            text-align: center;
        }

        .cover .qr-container img {
            width: 120px;
            height: 120px;
            border: 3px solid #fff;
            padding: 5px;
        }

        /* Seções gerais */
        .section {
            padding: 15px;
            margin-bottom: 15px;
            border-bottom: 2px solid
                {{ $secundaryColor }}
            ;
            background: #f1f1f1;
            border-radius: 5px;
        }

        .sub-header {
            background-color:
                {{ $secundaryColor }}
            ;
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
            color:
                {{ $primaryColor }}
            ;
        }

        img {
            max-width: 100%;
            display: block;
            margin: 10px auto;
            border-radius: 5px;
            border: 1px solid #ddd;
        }

        /* Verificações */
        .verification-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            padding: 20px;
        }

        .verification-box {
            width: 48%;
            padding: 10px;
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            border-radius: 5px;
            margin-bottom: 10px;
        }

        .verified {
            background-color: #28a745;
            color: white;
        }

        .not-verified {
            background-color: #ccc;
            color: black;
        }

        /* Cabeçalho e rodapé via MPDF */
        .page-header,
        .page-footer {
            width: 100%;
            font-size: 10px;
            text-align: center;
            color: #555;
        }

        .page-footer {
            border-top: 1px solid #ccc;
            padding-top: 5px;
        }
    </style>
</head>

<body>

    <!-- Cabeçalho fixo para todas as páginas -->
    <htmlpageheader name="page-header">
        <div class="page-header">
            Relatório de Pessoa Física<br>
            Relatório gerado em: {{ date('d/m/Y - H:i:s') }} 
        </div>
    </htmlpageheader>
    <sethtmlpageheader name="page-header" value="on" show-this-page="1" />

    <!-- Rodapé fixo para todas as páginas -->
    <htmlpagefooter name="page-footer">
        <div class="page-footer">
            Aviso: Este relatório pode conter dados fictícios, pois o sistema está em fase de testes.
        </div>
    </htmlpagefooter>
    <sethtmlpagefooter name="page-footer" value="on" />

    <!-- CAPA DO RELATÓRIO -->
    <div class="container cover">
        <img src="images/peterlogo.png" alt="Logo Peter Tecnet" class="img-fluid rounded"
            style="width: 180px; height: auto;">
        <h1>Relatório de Antecedentes Criminais</h1>
        <h2>{{ $person['name'] }}</h2>
        <p>Relatório gerado em: {{ date('d/m/Y - H:i:s') }}</p>
        <div class="qr-container">
            <img src="data:image/png;base64,{{ $photosBase64['qrCode'] }}" alt="QR Code">
        </div>
    </div>

    <!-- PÁGINA 2 - DADOS PESSOAIS -->
    <div class="container">
        <div class="section">
            <div class="sub-header">Dados Pessoais</div>
            <div class="info">
                <strong>Nome:</strong> {{ $person['name'] }} <br>
                <strong>RG:</strong> {{ $person['rg'] ?? 'N/A' }} <br>
                <strong>CPF:</strong> {{ $person['cpf'] ?? 'N/A' }} <br>
                <strong>Nome do Pai:</strong> {{ $person['father'] ?? 'N/A' }} <br>
                <strong>Nome da Mãe:</strong> {{ $person['mother'] ?? 'N/A' }} <br>
                <strong>Data de Nascimento:</strong> {{ $person['dob'] ?? 'N/A' }}
            </div>
        </div>

        <!-- FOTOS DOS DOCUMENTOS -->
        <div class="section">
            <div class="sub-header">Fotos dos Documentos</div>
            <img src="{{ $docs['documentFront']['photo'] }}" alt="Documento Frente" style="width:250px;">
            <img src="{{ $docs['documentBack']['photo'] }}" alt="Documento Verso" style="width:250px;">
            <img src="{{ $photosBase64['faceMatchPerson'] }}" alt="Selfie" style="width:250px;">
        </div>
    </div>

    <!-- PÁGINA 3 - VERIFICAÇÕES -->
    <div class="container">
        <div class="sub-header">Verificações Realizadas</div>
        <div class="verification-container">
            <div class="verification-box {{ isset($verifications['identities']) ? 'verified' : 'not-verified' }}">
                Identidade Verificada
            </div>
            <div class="verification-box {{ isset($verifications['credit']) ? 'verified' : 'not-verified' }}">
                Crédito Social
            </div>
            <div class="verification-box {{ isset($verifications['criminal']) ? 'verified' : 'not-verified' }}">
                Compliance
            </div>
        </div>
    </div>

    <!-- PÁGINAS TRF1 - TRF6 (cada TRF em uma nova página) -->
    @foreach(['TRF1', 'TRF2', 'TRF3', 'TRF4', 'TRF5', 'TRF6'] as $trf)
        <div class="container">
            <div class="sub-header">Consulta {{ $trf }}</div>
            <div class="info">
                <strong>Situação:</strong> {{ $verifications[$trf]['status'] ?? 'Não disponível' }} <br>
                <strong>Data da Consulta:</strong> {{ $verifications[$trf]['date'] ?? 'N/A' }} <br>
                <strong>Identificação:</strong> {{ $verifications[$trf]['id'] ?? 'N/A' }}
            </div>
        </div>
    @endforeach

    <!-- OUTRAS SESSÕES -->
    <div class="container">
        <div class="sub-header">Situação Cadastral do CPF</div>
        <p>{{ $person['cpf_status'] ?? 'N/A' }}</p>
    </div>

    <div class="container">
        <div class="sub-header">Benefícios e Assistência Social</div>
        <p>{{ $person['social_benefits'] ?? 'N/A' }}</p>
    </div>

    <!-- RESUMO FINAL -->
    <div class="container">
        <div class="sub-header">Resumo Geral</div>
        <p>Todas as verificações e dados apresentados neste relatório foram processados automaticamente.</p>
    </div>

    <!-- Página de Certidões (exemplo de capa final) -->
    <div class="container cover">
        <h1>Comprovante e Certidões</h1>
        <p>Este relatório inclui certidões de antecedentes criminais e outras informações legais relevantes.</p>
    </div>

</body>

</html>