<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#050816">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <meta name="description" content="Documentação inicial da API pública Peter Tecnet: URL base, autenticação, recursos disponíveis e exemplos de integração.">
    <link rel="canonical" href="https://api.petertecnet.com.br/docs">
    <title>Documentação da API | Peter Tecnet</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #050816;
            --surface: rgba(13, 20, 43, .78);
            --surface-strong: #101a36;
            --line: rgba(148, 163, 184, .18);
            --text: #f8fafc;
            --muted: #a9b6cc;
            --primary: #5f8cff;
            --primary-strong: #7da2ff;
            --accent: #4fe7c3;
            --code: #070b18;
            --shadow: 0 24px 70px rgba(0, 0, 0, .28);
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at 15% 0%, rgba(95, 140, 255, .15), transparent 35%),
                radial-gradient(circle at 100% 20%, rgba(79, 231, 195, .09), transparent 28%),
                var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.6;
        }

        a { color: inherit; }
        .shell { width: min(1180px, calc(100% - 32px)); margin: 0 auto; }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            border-bottom: 1px solid var(--line);
            background: rgba(5, 8, 22, .84);
            backdrop-filter: blur(18px);
        }
        .nav {
            min-height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 11px;
            text-decoration: none;
            font-weight: 800;
            letter-spacing: -.02em;
        }
        .brand-mark {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: linear-gradient(145deg, #2449a9, #6e93ff);
            box-shadow: 0 8px 30px rgba(95, 140, 255, .26);
            font-size: 12px;
            letter-spacing: .04em;
        }
        .nav-links { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .nav-links a {
            text-decoration: none;
            color: var(--muted);
            font-size: 14px;
            font-weight: 700;
        }
        .nav-links a:hover { color: var(--text); }
        .hero { padding: 78px 0 44px; }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(79, 231, 195, .25);
            border-radius: 999px;
            padding: 7px 11px;
            background: rgba(79, 231, 195, .07);
            color: #9ef7e1;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .12em;
        }
        .eyebrow::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 18px var(--accent);
        }
        h1 {
            max-width: 850px;
            margin: 22px 0 18px;
            font-size: clamp(2.5rem, 7vw, 5rem);
            line-height: .98;
            letter-spacing: -.055em;
        }
        .hero p { max-width: 760px; margin: 0; color: var(--muted); font-size: clamp(1rem, 2vw, 1.2rem); }
        .layout {
            display: grid;
            grid-template-columns: 250px minmax(0, 1fr);
            gap: 28px;
            align-items: start;
            padding-bottom: 90px;
        }
        .sidebar {
            position: sticky;
            top: 94px;
            padding: 18px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: rgba(13, 20, 43, .6);
        }
        .sidebar strong { display: block; margin-bottom: 10px; font-size: 12px; text-transform: uppercase; letter-spacing: .12em; color: #d9e2f2; }
        .sidebar a { display: block; padding: 8px 10px; border-radius: 10px; text-decoration: none; color: var(--muted); font-size: 14px; }
        .sidebar a:hover { background: rgba(95, 140, 255, .08); color: var(--text); }
        .content { min-width: 0; }
        .section {
            margin-bottom: 22px;
            padding: clamp(22px, 4vw, 34px);
            border: 1px solid var(--line);
            border-radius: 24px;
            background: var(--surface);
            box-shadow: var(--shadow);
        }
        .section h2 { margin: 0 0 12px; font-size: clamp(1.45rem, 3vw, 2rem); letter-spacing: -.03em; }
        .section h3 { margin: 24px 0 8px; font-size: 1rem; }
        .section p { color: var(--muted); margin: 0 0 15px; }
        .section p:last-child { margin-bottom: 0; }
        .base-url {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            border: 1px solid rgba(95, 140, 255, .24);
            border-radius: 14px;
            background: rgba(95, 140, 255, .06);
            overflow-wrap: anywhere;
        }
        .base-url code { color: #dce6ff; font-size: .95rem; }
        .pill { border-radius: 999px; padding: 4px 8px; background: rgba(79, 231, 195, .1); color: #9ef7e1; font-size: 11px; font-weight: 800; white-space: nowrap; }
        pre {
            margin: 15px 0 0;
            padding: 18px;
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--code);
            color: #d9e7ff;
            font-size: 13px;
            line-height: 1.65;
        }
        code { font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .card { padding: 18px; border: 1px solid var(--line); border-radius: 16px; background: rgba(255,255,255,.025); }
        .card strong { display: block; margin-bottom: 5px; }
        .card span { color: var(--muted); font-size: 14px; }
        .endpoint-list { display: grid; gap: 10px; }
        .endpoint {
            display: grid;
            grid-template-columns: 58px minmax(0, 1fr);
            gap: 10px;
            align-items: center;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 13px;
            background: rgba(255,255,255,.02);
        }
        .method { color: #9ef7e1; font-size: 12px; font-weight: 900; letter-spacing: .06em; }
        .endpoint code { min-width: 0; overflow-wrap: anywhere; color: #dce6ff; font-size: 13px; }
        .note {
            padding: 14px 16px;
            border-left: 3px solid var(--primary);
            border-radius: 0 12px 12px 0;
            background: rgba(95, 140, 255, .07);
            color: #c8d5eb;
            font-size: 14px;
        }
        footer { padding: 0 0 48px; color: #7f8ba3; font-size: 13px; }
        @media (max-width: 840px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: static; display: flex; gap: 5px; overflow-x: auto; }
            .sidebar strong { display: none; }
            .sidebar a { white-space: nowrap; }
        }
        @media (max-width: 620px) {
            .shell { width: min(100% - 22px, 1180px); }
            .hero { padding-top: 52px; }
            .grid { grid-template-columns: 1fr; }
            .nav-links a:not(.home-link) { display: none; }
            .base-url { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
<header class="topbar">
    <div class="shell nav">
        <a class="brand" href="{{ url('/') }}" aria-label="API Peter Tecnet">
            <span class="brand-mark">PT</span>
            <span>Peter Tecnet API</span>
        </a>
        <nav class="nav-links" aria-label="Navegação principal">
            <a class="home-link" href="{{ url('/') }}">Visão geral</a>
            <a href="https://petertecnet.com.br" rel="noopener">Peter Tecnet</a>
        </nav>
    </div>
</header>

<main class="shell">
    <section class="hero">
        <span class="eyebrow">Documentação pública</span>
        <h1>Integre com o ecossistema Peter Tecnet.</h1>
        <p>Esta é a documentação inicial da API pública Peter Tecnet. Ela apresenta a base de integração, os recursos de acesso aberto e o padrão de autenticação usado nas operações protegidas.</p>
    </section>

    <div class="layout">
        <aside class="sidebar" aria-label="Seções da documentação">
            <strong>Nesta página</strong>
            <a href="#inicio">Primeiros passos</a>
            <a href="#autenticacao">Autenticação</a>
            <a href="#recursos">Recursos</a>
            <a href="#endpoints">Endpoints públicos</a>
            <a href="#boas-praticas">Boas práticas</a>
        </aside>

        <div class="content">
            <section class="section" id="inicio">
                <h2>Primeiros passos</h2>
                <p>Todas as integrações partem da URL base abaixo e utilizam JSON como formato principal de troca de dados.</p>
                <div class="base-url">
                    <code>https://api.petertecnet.com.br/api</code>
                    <span class="pill">HTTPS</span>
                </div>
                <h3>Exemplo de leitura pública</h3>
                <pre><code>curl --request GET \
  --url https://api.petertecnet.com.br/api/establishment \
  --header 'Accept: application/json'</code></pre>
            </section>

            <section class="section" id="autenticacao">
                <h2>Autenticação</h2>
                <p>A API pode ser utilizada publicamente, mas operações que acessam dados privados ou alteram recursos continuam protegidas. Quando o endpoint exigir autenticação, envie o token no cabeçalho <code>Authorization</code>.</p>
                <pre><code>Authorization: Bearer SEU_TOKEN
Accept: application/json
Content-Type: application/json</code></pre>
                <div class="note">Acesso público não significa acesso irrestrito: permissões, escopo do usuário, contexto da aplicação e limites de requisição continuam sendo aplicados no servidor.</div>
            </section>

            <section class="section" id="recursos">
                <h2>Recursos disponíveis</h2>
                <div class="grid">
                    <div class="card"><strong>Identidade e contas</strong><span>Cadastro, login, Google, perfil e sessão.</span></div>
                    <div class="card"><strong>Estabelecimentos</strong><span>Descoberta, consulta e dados de empresas e operações.</span></div>
                    <div class="card"><strong>Catálogo e itens</strong><span>Produtos, serviços, menus e recursos comerciais.</span></div>
                    <div class="card"><strong>Pedidos e atendimento</strong><span>Fluxos de pedido, acompanhamento e registros de serviço.</span></div>
                    <div class="card"><strong>Eventos e ingressos</strong><span>Eventos, produções e recursos de ticketing.</span></div>
                    <div class="card"><strong>Conteúdo</strong><span>Notícias e recursos de descoberta consumidos pelas plataformas.</span></div>
                </div>
            </section>

            <section class="section" id="endpoints">
                <h2>Alguns endpoints públicos</h2>
                <p>Os exemplos abaixo são pontos de entrada de leitura já disponíveis. A documentação será ampliada progressivamente com schemas, filtros e exemplos de resposta.</p>
                <div class="endpoint-list">
                    <div class="endpoint"><span class="method">GET</span><code>/api/establishment</code></div>
                    <div class="endpoint"><span class="method">GET</span><code>/api/item/index</code></div>
                    <div class="endpoint"><span class="method">GET</span><code>/api/event</code></div>
                    <div class="endpoint"><span class="method">GET</span><code>/api/ticket</code></div>
                    <div class="endpoint"><span class="method">GET</span><code>/api/news</code></div>
                </div>
            </section>

            <section class="section" id="boas-praticas">
                <h2>Boas práticas de integração</h2>
                <div class="grid">
                    <div class="card"><strong>Use HTTPS</strong><span>Nunca envie tokens ou dados de usuário por conexões inseguras.</span></div>
                    <div class="card"><strong>Respeite rate limits</strong><span>Implemente cache, backoff e evite polling desnecessário.</span></div>
                    <div class="card"><strong>Não exponha tokens</strong><span>Credenciais sensíveis não devem ficar em URLs, logs ou código público.</span></div>
                    <div class="card"><strong>Prepare-se para evolução</strong><span>Prefira recursos versionados e integrações desacopladas de uma plataforma específica.</span></div>
                </div>
            </section>
        </div>
    </div>
</main>

<footer class="shell">
    Peter Tecnet API · infraestrutura pública para produtos, integrações e experiências digitais conectadas.
</footer>
</body>
</html>
