<!doctype html>
<html lang="pt-BR"><body style="font-family:Arial,sans-serif;color:#1f2937;line-height:1.6">
<h2>Termo de adesão confirmado</h2>
<p>Olá,</p>
<p>O termo da organização <strong>{{ $organization->name }}</strong> foi assinado para uso da aplicação <strong>{{ $application->name ?? 'Peter Tecnet' }}</strong>.</p>
<p>Versão: {{ $acceptance->contract_version }}<br>Aceite: {{ $acceptance->accepted_at }}</p>
<p>A cópia em PDF está anexada a esta mensagem.</p>
<p>Peter Tecnet</p>
</body></html>
