<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#050816">
    <meta name="robots" content="index,follow">
    <meta name="description" content="@yield('description')">
    <link rel="canonical" href="@yield('canonical')">
    <title>@yield('title') | Peter Tecnet API</title>
    <style>
        :root{color-scheme:dark;--bg:#050816;--surface:#0d142bd9;--line:#25314d;--text:#f8fafc;--muted:#a8b5ca;--primary:#6f92ff;--accent:#55e8c4}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 14% 0,#182c60 0,transparent 30%),var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif;line-height:1.65}a{color:#aec1ff}.shell{width:min(980px,calc(100% - 28px));margin:auto}.topbar{position:sticky;top:0;z-index:10;background:#050816e5;border-bottom:1px solid var(--line);backdrop-filter:blur(16px)}.nav{min-height:68px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{font-weight:900;color:var(--text);text-decoration:none}.links{display:flex;gap:13px;font-size:13px}.hero{padding:65px 0 26px}.eyebrow{color:var(--accent);font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.12em}h1{font-size:clamp(2.5rem,7vw,4.8rem);line-height:.97;letter-spacing:-.05em;margin:11px 0 16px}h2{margin-top:32px;letter-spacing:-.025em}h3{margin-top:24px}.lead,p,li{color:var(--muted)}.panel{border:1px solid var(--line);border-radius:22px;background:var(--surface);padding:clamp(20px,4vw,34px);margin:18px 0 60px;box-shadow:0 18px 55px #0004}.note{border-left:3px solid var(--primary);padding:13px 15px;background:#5f8cff12;border-radius:0 12px 12px 0;color:#cbd8ed}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.card{border:1px solid var(--line);border-radius:14px;padding:15px;background:#080e20}.card strong{display:block}.card span{font-size:13px;color:var(--muted)}code{background:#080d1d;border:1px solid var(--line);padding:2px 6px;border-radius:6px;color:#dce6ff}.status{display:inline-flex;align-items:center;gap:8px;border:1px solid #55e8c43b;border-radius:999px;background:#55e8c410;color:#a5f5e2;padding:6px 10px;font-weight:850;font-size:12px}.dot{width:8px;height:8px;border-radius:50%;background:var(--accent)}.footer{padding-bottom:50px;color:var(--muted);font-size:13px}@media(max-width:650px){.grid{grid-template-columns:1fr}.links a:not(:first-child){display:none}}
    </style>
    @stack('head')
</head>
<body>
<header class="topbar"><div class="shell nav"><a class="brand" href="/">Peter Tecnet API</a><nav class="links"><a href="/docs">Docs</a><a href="/developers">Developer Portal</a><a href="/status">Status</a><a href="/changelog">Changelog</a></nav></div></header>
<main class="shell"><section class="hero"><span class="eyebrow">@yield('eyebrow')</span><h1>@yield('heading')</h1><p class="lead">@yield('lead')</p></section><article class="panel">@yield('content')</article></main>
<footer class="shell footer">Peter Tecnet Public API v1 · <a href="/terms">Termos</a> · <a href="/privacy">Privacidade</a> · <a href="/deprecation">Depreciação</a> · <a href="/openapi.json">OpenAPI</a></footer>
@stack('scripts')
</body>
</html>
