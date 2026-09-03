<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;line-height:1.5;color:#111}h1{font-size:18px}h2{font-size:13px;margin-top:18px}.meta{background:#f4f4f4;padding:10px;margin-bottom:18px}.signature{margin-top:28px;border-top:1px solid #999;padding-top:12px}</style></head>
<body>
<h1>Termo de adesão e prestação de serviços</h1>
<div class="meta">
    <strong>Aplicação:</strong> {{ $application->name ?? 'Peter Tecnet' }}<br>
    <strong>Organização:</strong> {{ $organization->name }}<br>
    <strong>Versão:</strong> {{ $acceptance->contract_version }}<br>
    <strong>Hash:</strong> {{ $acceptance->contract_hash }}<br>
    <strong>Aceito em:</strong> {{ $acceptance->accepted_at }}
</div>
<div>{!! nl2br(e($acceptance->contract_snapshot)) !!}</div>
<div class="signature">
    <strong>Signatário:</strong> {{ $acceptance->signer_name }}<br>
    <strong>Documento:</strong> {{ $acceptance->signer_document }}<br>
    @if($acceptance->signer_role)<strong>Função:</strong> {{ $acceptance->signer_role }}<br>@endif
    <strong>IP registrado:</strong> {{ $acceptance->ip_address }}
</div>
</body></html>
