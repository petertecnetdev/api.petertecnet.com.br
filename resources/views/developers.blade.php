<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Peter Platform — Developers</title>
    <meta name="description" content="Documentação e integração da Peter Platform API v1.">
    <style>
        :root{color-scheme:dark;--bg:#07111f;--panel:#0d1b2d;--text:#eef7ff;--muted:#9fb6ca;--accent:#35bdf6;--border:#18334c}*{box-sizing:border-box}body{margin:0;font:16px/1.6 Inter,system-ui,sans-serif;background:linear-gradient(145deg,#07111f,#091827);color:var(--text)}main{max-width:1100px;margin:auto;padding:64px 24px}.eyebrow{color:var(--accent);font-weight:700;letter-spacing:.12em;text-transform:uppercase}h1{font-size:clamp(2.5rem,7vw,5.5rem);line-height:1;margin:.25em 0}.lead{font-size:1.2rem;color:var(--muted);max-width:760px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin:40px 0}.card{background:rgba(13,27,45,.82);border:1px solid var(--border);border-radius:18px;padding:24px}.card h2{margin-top:0}code,pre{font-family:ui-monospace,SFMono-Regular,Consolas,monospace}pre{overflow:auto;background:#030a12;border:1px solid var(--border);padding:18px;border-radius:14px;color:#c8edff}a{color:var(--accent)}.pill{display:inline-block;padding:6px 10px;border:1px solid var(--border);border-radius:999px;margin:4px;color:var(--muted)}</style>
</head>
<body><main>
    <div class="eyebrow">Peter Tecnet Developer Platform</div>
    <h1>Você cria o produto.<br>A Peter cuida da infraestrutura.</h1>
    <p class="lead">API v1 estável para identidade, aplicações, organizações, catálogo, comércio, pagamentos, eventos, pessoas, auditoria e integrações. Projetos externos usam sandbox ou produção com API Key ou OAuth 2.0.</p>
    <div class="grid">
        <section class="card"><h2>Contrato estável</h2><p>Endpoints versionados em <code>/api/v1</code>, contexto de aplicação obrigatório e respostas previsíveis.</p><span class="pill">v1</span><span class="pill">OpenAPI 3.1</span></section>
        <section class="card"><h2>Autenticação</h2><p>API Keys e OAuth 2.0 <code>client_credentials</code>, scopes por projeto, quotas e rate limiting.</p><span class="pill">OAuth</span><span class="pill">Scopes</span></section>
        <section class="card"><h2>Confiabilidade</h2><p>Idempotência para escritas externas, webhooks assinados com retry, auditoria e medição de uso.</p><span class="pill">Webhooks</span><span class="pill">Metering</span></section>
    </div>
    <h2>Primeira chamada</h2>
    <pre>curl -H "X-API-Key: pt_test_..." \
  https://api.petertecnet.com.br/api/v1/apps/seu-app/platform/items</pre>
    <h2>OAuth</h2>
    <pre>POST /api/v1/oauth/token
{
  "grant_type": "client_credentials",
  "client_id": "...",
  "client_secret": "...",
  "scope": "catalog.read"
}</pre>
    <p>Especificação técnica: <a href="/developers/openapi.yaml">OpenAPI YAML</a>.</p>
</main></body></html>
