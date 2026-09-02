<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<style>
body{font-family:DejaVu Sans,sans-serif;color:#17171c;font-size:11px;line-height:1.55}h1{font-size:20px;margin:0 0 4px}h2{font-size:13px;margin:18px 0 6px}.muted{color:#666}.box{border:1px solid #ddd;border-radius:8px;padding:12px;margin:14px 0}.contract{white-space:pre-line}.signature{margin-top:22px;border-top:1px solid #ccc;padding-top:12px}.brand{font-weight:700;font-size:14px}.footer{margin-top:24px;font-size:9px;color:#777}
</style>
</head>
<body>
<div class="brand">CUTINAPP · Peter Tecnet</div>
<h1>Termo de Adesão do Produtor</h1>
<div class="muted">Versão {{ $acceptance->contract_version }}</div>
<div class="box">
<strong>Produção:</strong> {{ $production->name }}<br>
<strong>CNPJ:</strong> {{ $production->cnpj ?: 'não informado' }}<br>
<strong>ID da produção:</strong> {{ $production->id }}
</div>
<div class="contract">{{ $acceptance->contract_snapshot }}</div>
<div class="signature">
<h2>Registro da assinatura eletrônica</h2>
<strong>Signatário:</strong> {{ $acceptance->signer_name }}<br>
<strong>Documento:</strong> {{ $acceptance->signer_document }}<br>
@if($acceptance->signer_role)<strong>Qualificação:</strong> {{ $acceptance->signer_role }}<br>@endif
<strong>Data/hora:</strong> {{ $acceptance->accepted_at }}<br>
<strong>IP:</strong> {{ $acceptance->ip_address ?: 'não registrado' }}<br>
<strong>Hash SHA-256 do contrato:</strong><br>{{ $acceptance->contract_hash }}
</div>
<div class="footer">Documento gerado eletronicamente pela Cutinapp. O hash acima identifica a versão integral do conteúdo aceito.</div>
</body>
</html>
