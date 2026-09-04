<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $context['subject'] }}</title>
    <style>
        body,html{margin:0;padding:0}body{background:#081723;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;color:#fff;line-height:1.6}.container{background:#102838;max-width:640px;margin:40px auto;padding:32px;border:1px solid #1bbcff;border-radius:16px;box-shadow:0 12px 32px rgba(0,0,0,.28)}.logo{display:block;margin:0 auto 22px;max-width:130px;height:auto}h1{font-size:1.8rem;text-align:center;color:#65d7ff;margin:0 0 18px}p{font-size:1rem;color:#f3f8fb}.context{background:#0b1f2d;border:1px solid rgba(101,215,255,.25);padding:16px 18px;border-radius:12px;margin:22px 0}.context strong{color:#65d7ff}.features{margin:22px 0;padding:0;list-style:none}.features li{background:#0b1f2d;margin:10px 0;padding:13px 15px;border-radius:10px;border-left:3px solid #1bbcff}.action{display:block;margin:26px auto 10px;padding:14px 20px;border-radius:10px;background:#1bbcff;color:#071621!important;font-weight:800;text-align:center;text-decoration:none}.footer{text-align:center;padding:18px;font-size:.88rem;color:#b9cad3}@media(max-width:680px){.container{margin:18px;padding:22px}h1{font-size:1.45rem}}
    </style>
</head>
<body>
    <div class="container">
        <img src="https://petertecnet.com.br/petertecnetlogo.png" alt="Peter Tecnet" class="logo" />
        <h1>{{ $context['title'] }}</h1>
        <p>{{ $context['intro'] }}</p>

        <div class="context">
            <p><strong>Plataforma:</strong> {{ $context['app_name'] }}</p>
            <p><strong>Seu vínculo:</strong> {{ $context['relationship'] }}</p>
            @if(!empty($context['establishment_name']))
                <p><strong>Estabelecimento:</strong> {{ $context['establishment_name'] }}</p>
            @endif
        </div>

        <p><strong>O que você já pode fazer:</strong></p>
        <ul class="features">
            @foreach($context['features'] as $feature)
                <li>{{ $feature }}</li>
            @endforeach
        </ul>

        <a href="{{ $appUrl }}" class="action">Acessar {{ $context['app_name'] }}</a>
        <p>Este e-mail foi enviado porque sua conta recebeu ou teve atualizado um acesso dentro do ecossistema Peter Tecnet.</p>
    </div>
    <div class="footer">© {{ date('Y') }} Peter Tecnet. Todos os direitos reservados.</div>
</body>
</html>
