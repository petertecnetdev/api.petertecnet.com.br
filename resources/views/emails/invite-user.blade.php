<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ative seu acesso</title>
    <style>
        body,html{margin:0;padding:0}body{background:#0B1F30;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;color:#fff;line-height:1.6}.container{background:#132A3A;max-width:620px;margin:40px auto;padding:30px;border:1px solid #00BFFF;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.3)}.logo{display:block;margin:0 auto 20px;max-width:140px;height:auto}h1{font-size:1.8rem;text-align:center;color:#00BFFF;margin-bottom:20px}p{font-size:1rem;margin:18px 0;color:#fff}.verification-code{background:#00BFFF;color:#0B1F30;font-size:1.7rem;font-weight:800;text-align:center;padding:16px;margin:22px 0;border-radius:10px;letter-spacing:5px}.action{display:block;margin:24px auto;padding:14px 20px;border-radius:9px;background:#00BFFF;color:#071621!important;font-weight:800;text-align:center;text-decoration:none}.app-url{overflow-wrap:anywhere;text-align:center}.app-url a{color:#72dcff}.steps,.context{background:#0c2230;border-radius:10px;padding:16px 20px;margin:20px 0}.steps p,.context p{margin:6px 0}.features{padding-left:20px}.features li{margin:8px 0}.footer{text-align:center;padding:20px 0;font-size:.9rem;color:#fff}@media(max-width:600px){.container{margin:20px;padding:20px}h1{font-size:1.5rem}.verification-code{font-size:1.35rem;letter-spacing:3px}.logo{max-width:120px}}
    </style>
</head>
<body>
    <div class="container">
        <img src="https://petertecnet.com.br/petertecnetlogo.png" alt="Peter Tecnet" class="logo" />
        <h1>{{ $context['title'] ?? ('Seu acesso ao '.$appName.' está pronto') }}</h1>
        <p>{{ $context['intro'] ?? ('Preparamos seu cadastro para o aplicativo '.$appName.'.') }}</p>

        <div class="context">
            <p><strong>Plataforma:</strong> {{ $appName }}</p>
            @if(!empty($context['relationship']))
                <p><strong>Seu vínculo:</strong> {{ $context['relationship'] }}</p>
            @endif
            @if(!empty($context['establishment_name']))
                <p><strong>Estabelecimento:</strong> {{ $context['establishment_name'] }}</p>
            @endif
        </div>

        @if(!empty($context['features']))
            <p><strong>Depois da ativação você poderá:</strong></p>
            <ul class="features">
                @foreach($context['features'] as $feature)
                    <li>{{ $feature }}</li>
                @endforeach
            </ul>
        @endif

        <p>Para proteger sua conta, confirme que este e-mail é seu e crie a sua própria senha.</p>
        <p><strong>Seu código de verificação:</strong></p>
        <div class="verification-code">{{ $code }}</div>
        <div class="steps">
            <p>1. Clique no botão abaixo.</p>
            <p>2. Digite o código de verificação acima.</p>
            <p>3. Crie sua nova senha.</p>
            <p>4. Digite a mesma senha novamente para confirmar.</p>
            <p>5. Após a validação, seu acesso ao aplicativo será liberado.</p>
        </div>
        <a href="{{ $activationUrl }}" class="action">Validar e criar minha senha</a>
        <p>Endereço oficial do aplicativo:</p>
        <p class="app-url"><a href="{{ $appUrl }}">{{ $appUrl }}</a></p>
        <p>O código expira em 24 horas. Não encaminhe este código para outras pessoas. Se você não reconhece este convite, apenas ignore esta mensagem.</p>
    </div>
    <div class="footer"><p>© {{ date('Y') }} Peter Tecnet. Todos os direitos reservados.</p></div>
</body>
</html>
