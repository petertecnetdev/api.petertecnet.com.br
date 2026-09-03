<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#050816">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <meta name="description" content="API pública da Peter Tecnet para integrar aplicações, estabelecimentos, catálogos, pedidos, eventos, contas e outros recursos do ecossistema Peter Tecnet.">
    <meta name="keywords" content="Peter Tecnet API, API pública, API REST, integração de sistemas, desenvolvimento de software, API Brasil, integração de aplicações">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Peter Tecnet API — API pública para produtos conectados">
    <meta property="og:description" content="Uma API pública e evolutiva para construir integrações e experiências digitais conectadas ao ecossistema Peter Tecnet.">
    <meta property="og:url" content="https://api.petertecnet.com.br/">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="canonical" href="https://api.petertecnet.com.br/">
    <title>Peter Tecnet API — API pública para integrações e aplicações</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #050816;
            --bg-soft: #091128;
            --surface: rgba(12, 19, 42, .72);
            --line: rgba(148, 163, 184, .18);
            --text: #f8fafc;
            --muted: #a8b4ca;
            --primary: #5f8cff;
            --primary-soft: rgba(95, 140, 255, .13);
            --accent: #4fe7c3;
            --shadow: 0 24px 80px rgba(0, 0, 0, .34);
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            background:
                radial-gradient(circle at 18% -10%, rgba(95, 140, 255, .21), transparent 35%),
                radial-gradient(circle at 94% 18%, rgba(79, 231, 195, .10), transparent 27%),
                linear-gradient(180deg, #050816 0%, #070c1e 48%, #050816 100%);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.6;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            opacity: .25;
            background-image:
                linear-gradient(rgba(255,255,255,.018) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.018) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: linear-gradient(to bottom, black, transparent 78%);
        }

        a { color: inherit; }
        button, a { -webkit-tap-highlight-color: transparent; }
        .shell { width: min(1180px, calc(100% - 32px)); margin: 0 auto; position: relative; }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 30;
            border-bottom: 1px solid var(--line);
            background: rgba(5, 8, 22, .78);
            backdrop-filter: blur(18px);
        }
        .nav {
            min-height: 74px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            font-weight: 850;
            letter-spacing: -.025em;
        }
        .brand-mark {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 13px;
            background: linear-gradient(145deg, #2449a9, #7198ff);
            box-shadow: 0 9px 30px rgba(95, 140, 255, .28), inset 0 1px 0 rgba(255,255,255,.18);
            font-size: 12px;
            font-weight: 900;
            letter-spacing: .04em;
        }
        .brand small { display: block; color: #7f8ba3; font-size: 10px; letter-spacing: .09em; text-transform: uppercase; font-weight: 800; }
        .nav-links { display: flex; align-items: center; gap: 8px; }
        .nav-link {
            padding: 9px 12px;
            border-radius: 12px;
            color: var(--muted);
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            transition: color .2s ease, background .2s ease;
        }
        .nav-link:hover { color: var(--text); background: rgba(255,255,255,.045); }
        .nav-cta { color: #edf3ff; border: 1px solid rgba(95, 140, 255, .3); background: var(--primary-soft); }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1.12fr) minmax(340px, .88fr);
            gap: clamp(34px, 7vw, 84px);
            align-items: center;
            min-height: min(820px, calc(100vh - 74px));
            padding: 84px 0 76px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 7px 11px;
            border: 1px solid rgba(79, 231, 195, .24);
            border-radius: 999px;
            background: rgba(79, 231, 195, .07);
            color: #a2f7e3;
            font-size: 12px;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: .11em;
        }
        .badge-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 16px rgba(79, 231, 195, .9);
        }
        h1 {
            max-width: 780px;
            margin: 22px 0 20px;
            font-size: clamp(3rem, 7.6vw, 6.25rem);
            line-height: .92;
            letter-spacing: -.065em;
        }
        h1 span {
            background: linear-gradient(90deg, #ffffff 0%, #93afff 58%, #73ecd1 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .hero-copy {
            max-width: 700px;
            margin: 0;
            color: var(--muted);
            font-size: clamp(1.05rem, 2vw, 1.25rem);
        }
        .hero-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 30px; }
        .button {
            min-height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 0 17px;
            border: 1px solid var(--line);
            border-radius: 14px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 800;
            transition: transform .2s ease, border-color .2s ease, background .2s ease;
        }
        .button:hover { transform: translateY(-2px); border-color: rgba(148, 163, 184, .34); }
        .button-primary { background: linear-gradient(135deg, #4e75e8, #6d92ff); border-color: transparent; box-shadow: 0 14px 36px rgba(70, 111, 232, .24); }
        .button-secondary { background: rgba(255,255,255,.035); }
        .microcopy { margin-top: 15px; color: #758198; font-size: 13px; }

        .terminal {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, .18);
            border-radius: 24px;
            background: linear-gradient(180deg, rgba(13, 20, 43, .92), rgba(7, 11, 26, .96));
            box-shadow: var(--shadow);
        }
        .terminal::before {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            right: -65px;
            top: -70px;
            border-radius: 50%;
            background: rgba(95, 140, 255, .12);
            filter: blur(6px);
        }
        .terminal-head {
            min-height: 54px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 0 17px;
            border-bottom: 1px solid var(--line);
        }
        .terminal-dots { display: flex; gap: 6px; }
        .terminal-dots i { width: 8px; height: 8px; border-radius: 50%; background: #536078; }
        .terminal-title { color: #7f8ba3; font: 700 11px/1 ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .06em; }
        .terminal-body { padding: 24px; }
        .terminal-label { color: #77849d; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; }
        .endpoint-box {
            margin: 10px 0 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 13px 14px;
            border: 1px solid rgba(95, 140, 255, .22);
            border-radius: 13px;
            background: rgba(95, 140, 255, .06);
        }
        .endpoint-box code { min-width: 0; overflow-wrap: anywhere; color: #dce6ff; font-size: 12px; }
        .copy-button {
            flex: 0 0 auto;
            border: 0;
            border-radius: 9px;
            padding: 7px 9px;
            background: rgba(255,255,255,.07);
            color: #dce6ff;
            cursor: pointer;
            font: 800 11px/1 system-ui, sans-serif;
        }
        pre {
            margin: 0;
            overflow-x: auto;
            color: #c9d8f4;
            font: 500 12px/1.75 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        .code-muted { color: #60708d; }
        .code-green { color: #86ebcf; }
        .code-blue { color: #8eabff; }

        .trust-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-top: -12px;
            margin-bottom: 92px;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: rgba(9, 17, 40, .58);
        }
        .trust-item { padding: 18px 20px; border-right: 1px solid var(--line); }
        .trust-item:last-child { border-right: 0; }
        .trust-item strong { display: block; font-size: 15px; }
        .trust-item span { color: var(--muted); font-size: 12px; }

        .section { padding: 26px 0 84px; }
        .section-heading { max-width: 760px; margin-bottom: 30px; }
        .section-kicker { color: #89a7ff; font-size: 12px; font-weight: 900; text-transform: uppercase; letter-spacing: .13em; }
        h2 { margin: 8px 0 12px; font-size: clamp(2rem, 4.8vw, 3.8rem); line-height: 1.02; letter-spacing: -.048em; }
        .section-heading p { margin: 0; color: var(--muted); font-size: 1.02rem; }
        .feature-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 15px; }
        .feature {
            min-height: 205px;
            padding: 23px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: var(--surface);
            transition: transform .2s ease, border-color .2s ease, background .2s ease;
        }
        .feature:hover { transform: translateY(-3px); border-color: rgba(95,140,255,.30); background: rgba(14, 22, 48, .86); }
        .feature-icon {
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            margin-bottom: 23px;
            border: 1px solid rgba(95,140,255,.22);
            border-radius: 12px;
            background: rgba(95,140,255,.09);
            color: #a9bdff;
            font-size: 17px;
            font-weight: 900;
        }
        .feature h3 { margin: 0 0 8px; font-size: 17px; letter-spacing: -.02em; }
        .feature p { margin: 0; color: var(--muted); font-size: 14px; }

        .public-api {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(300px, .78fr);
            gap: 28px;
            align-items: stretch;
            padding: clamp(28px, 5vw, 48px);
            border: 1px solid rgba(95,140,255,.20);
            border-radius: 26px;
            background:
                linear-gradient(120deg, rgba(95,140,255,.12), rgba(79,231,195,.045)),
                rgba(9, 16, 37, .70);
            box-shadow: var(--shadow);
        }
        .public-api h2 { margin-top: 0; }
        .public-api p { color: var(--muted); }
        .principles { display: grid; gap: 10px; }
        .principle { padding: 15px 16px; border: 1px solid var(--line); border-radius: 14px; background: rgba(5,8,22,.45); }
        .principle strong { display: block; margin-bottom: 3px; font-size: 14px; }
        .principle span { color: var(--muted); font-size: 13px; }

        .cta {
            margin: 70px 0 84px;
            padding: clamp(34px, 6vw, 64px);
            text-align: center;
            border: 1px solid var(--line);
            border-radius: 28px;
            background: radial-gradient(circle at 50% 0%, rgba(95,140,255,.16), transparent 46%), rgba(9,16,37,.65);
        }
        .cta h2 { max-width: 780px; margin: 0 auto 14px; }
        .cta p { max-width: 650px; margin: 0 auto 25px; color: var(--muted); }
        .cta .hero-actions { justify-content: center; margin-top: 0; }
        footer { padding: 0 0 44px; }
        .footer-inner { display: flex; align-items: center; justify-content: space-between; gap: 18px; padding-top: 25px; border-top: 1px solid var(--line); color: #78849a; font-size: 12px; }
        .footer-inner a { text-decoration: none; }

        @media (max-width: 900px) {
            .hero { grid-template-columns: 1fr; min-height: 0; padding-top: 68px; }
            .terminal { max-width: 720px; }
            .feature-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .public-api { grid-template-columns: 1fr; }
            .trust-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .trust-item:nth-child(2) { border-right: 0; }
            .trust-item:nth-child(-n+2) { border-bottom: 1px solid var(--line); }
        }
        @media (max-width: 620px) {
            .shell { width: min(100% - 22px, 1180px); }
            .nav { min-height: 66px; }
            .brand small { display: none; }
            .nav-link:not(.nav-cta) { display: none; }
            .hero { padding: 54px 0 58px; }
            h1 { font-size: clamp(3rem, 17vw, 5rem); }
            .hero-actions { flex-direction: column; }
            .button { width: 100%; }
            .terminal-body { padding: 18px; }
            .endpoint-box { align-items: flex-start; flex-direction: column; }
            .trust-strip { grid-template-columns: 1fr; margin-bottom: 70px; }
            .trust-item, .trust-item:nth-child(2) { border-right: 0; border-bottom: 1px solid var(--line); }
            .trust-item:last-child { border-bottom: 0; }
            .feature-grid { grid-template-columns: 1fr; }
            .section { padding-bottom: 66px; }
            .footer-inner { align-items: flex-start; flex-direction: column; }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after { transition-duration: .01ms !important; animation-duration: .01ms !important; }
        }
    </style>
</head>
<body>
<header class="topbar">
    <div class="shell nav">
        <a class="brand" href="{{ url('/') }}" aria-label="Peter Tecnet API - início">
            <span class="brand-mark">PT</span>
            <span>Peter Tecnet API<small>Developer Platform</small></span>
        </a>
        <nav class="nav-links" aria-label="Navegação principal">
            <a class="nav-link" href="#recursos">Recursos</a>
            <a class="nav-link" href="#publica">API pública</a>
            <a class="nav-link nav-cta" href="{{ url('/docs') }}">Documentação</a>
        </nav>
    </div>
</header>

<main>
    <section class="shell hero">
        <div>
            <span class="badge"><span class="badge-dot"></span> API pública Peter Tecnet</span>
            <h1>Uma API para <span>produtos conectados.</span></h1>
            <p class="hero-copy">A infraestrutura que conecta as plataformas Peter Tecnet também está aberta para integrações públicas. Construa experiências digitais usando recursos compartilhados de identidade, estabelecimentos, catálogos, pedidos, eventos e muito mais.</p>
            <div class="hero-actions">
                <a class="button button-primary" href="{{ url('/docs') }}">Explorar documentação <span aria-hidden="true">→</span></a>
                <a class="button button-secondary" href="#quickstart">Ver exemplo de integração</a>
            </div>
            <p class="microcopy">REST · JSON · HTTPS · autenticação em operações protegidas</p>
        </div>

        <div class="terminal" id="quickstart" aria-label="Exemplo de integração com a API">
            <div class="terminal-head">
                <div class="terminal-dots" aria-hidden="true"><i></i><i></i><i></i></div>
                <span class="terminal-title">QUICKSTART</span>
            </div>
            <div class="terminal-body">
                <div class="terminal-label">Base URL</div>
                <div class="endpoint-box">
                    <code id="base-url">https://api.petertecnet.com.br/api</code>
                    <button class="copy-button" type="button" data-copy-target="base-url">Copiar</button>
                </div>
                <pre><code><span class="code-muted"># Descubra estabelecimentos públicos</span>
curl <span class="code-green">https://api.petertecnet.com.br/api/establishment</span> \
  -H <span class="code-blue">"Accept: application/json"</span>

<span class="code-muted"># Respostas da API utilizam JSON</span></code></pre>
            </div>
        </div>
    </section>

    <div class="shell trust-strip" aria-label="Características da API">
        <div class="trust-item"><strong>REST + JSON</strong><span>Integração simples e interoperável</span></div>
        <div class="trust-item"><strong>HTTPS</strong><span>Comunicação segura em produção</span></div>
        <div class="trust-item"><strong>Multiplataforma</strong><span>Uma base para diferentes produtos</span></div>
        <div class="trust-item"><strong>Evolutiva</strong><span>Arquitetura preparada para novos domínios</span></div>
    </div>

    <section class="shell section" id="recursos">
        <div class="section-heading">
            <span class="section-kicker">O que você pode integrar</span>
            <h2>Recursos compartilhados, não uma API presa a um único aplicativo.</h2>
            <p>A Peter Tecnet evolui a API central como uma plataforma genérica e reutilizável. Isso permite que os mesmos recursos atendam diferentes produtos e também novas integrações externas.</p>
        </div>
        <div class="feature-grid">
            <article class="feature"><div class="feature-icon">ID</div><h3>Identidade e contas</h3><p>Cadastro, autenticação, sessão, perfis e integração com a Conta Peter Tecnet.</p></article>
            <article class="feature"><div class="feature-icon">E</div><h3>Estabelecimentos</h3><p>Consulte empresas, estabelecimentos e estruturas operacionais disponíveis publicamente.</p></article>
            <article class="feature"><div class="feature-icon">#</div><h3>Catálogos e itens</h3><p>Produtos, serviços, menus e outros itens consumidos por experiências comerciais.</p></article>
            <article class="feature"><div class="feature-icon">↗</div><h3>Pedidos e serviços</h3><p>Fluxos operacionais para pedidos, atendimento e registros relacionados à execução de serviços.</p></article>
            <article class="feature"><div class="feature-icon">EV</div><h3>Eventos e ingressos</h3><p>Recursos de eventos, produções e ticketing compartilhados com produtos do ecossistema.</p></article>
            <article class="feature"><div class="feature-icon">API</div><h3>Novos domínios</h3><p>A arquitetura continua crescendo para suportar novas aplicações sem duplicar soluções específicas.</p></article>
        </div>
    </section>

    <section class="shell section" id="publica">
        <div class="public-api">
            <div>
                <span class="section-kicker">Aberta para construir</span>
                <h2>Pública não significa desprotegida.</h2>
                <p>Queremos que desenvolvedores possam conhecer e utilizar a API Peter Tecnet. Recursos de leitura pública podem ser consumidos diretamente; operações que alteram dados, acessam informações privadas ou dependem de permissões continuam exigindo autenticação e autorização.</p>
                <a class="button button-primary" href="{{ url('/docs') }}">Começar pela documentação</a>
            </div>
            <div class="principles">
                <div class="principle"><strong>Acesso público</strong><span>A API pode ser descoberta, estudada e integrada por terceiros.</span></div>
                <div class="principle"><strong>Segurança por contexto</strong><span>Tokens, permissões e escopos continuam protegendo ações sensíveis.</span></div>
                <div class="principle"><strong>Arquitetura genérica</strong><span>Recursos reutilizáveis são priorizados em vez de endpoints exclusivos por aplicativo.</span></div>
                <div class="principle"><strong>Evolução documentada</strong><span>A documentação pública cresce junto com os recursos estáveis da plataforma.</span></div>
            </div>
        </div>
    </section>

    <section class="shell cta">
        <span class="section-kicker">Peter Tecnet Developer Platform</span>
        <h2>Use a mesma infraestrutura que conecta nosso ecossistema.</h2>
        <p>Conheça os primeiros endpoints públicos, o modelo de autenticação e as práticas recomendadas para começar sua integração.</p>
        <div class="hero-actions">
            <a class="button button-primary" href="{{ url('/docs') }}">Abrir documentação</a>
            <a class="button button-secondary" href="https://petertecnet.com.br" rel="noopener">Conhecer a Peter Tecnet</a>
        </div>
    </section>
</main>

<footer>
    <div class="shell footer-inner">
        <span>© {{ date('Y') }} Peter Tecnet. API pública para integrações e produtos digitais.</span>
        <a href="https://petertecnet.com.br" rel="noopener">petertecnet.com.br</a>
    </div>
</footer>

<script>
    (() => {
        const buttons = document.querySelectorAll('[data-copy-target]');
        buttons.forEach((button) => {
            button.addEventListener('click', async () => {
                const target = document.getElementById(button.dataset.copyTarget);
                if (!target) return;
                const value = target.textContent.trim();
                try {
                    await navigator.clipboard.writeText(value);
                    const original = button.textContent;
                    button.textContent = 'Copiado';
                    window.setTimeout(() => { button.textContent = original; }, 1400);
                } catch (_) {
                    button.textContent = 'Selecione e copie';
                }
            });
        });
    })();
</script>
</body>
</html>
