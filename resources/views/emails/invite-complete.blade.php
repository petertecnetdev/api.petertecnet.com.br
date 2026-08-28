<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso ativado</title>
</head>
<body style="margin:0;padding:24px;background:#0B1F30;color:#ffffff;font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;">
    <div style="max-width:600px;margin:0 auto;background:#132A3A;border:1px solid #00BFFF;border-radius:8px;padding:32px;">
        <h1 style="margin-top:0;color:#00BFFF;">Acesso ativado</h1>
        <p>Olá {{ $user->first_name }},</p>
        <p>Seu convite foi concluído e sua conta está pronta para acessar as aplicações Peter Tecnet às quais você foi vinculado.</p>
        <p>Se você não realizou esta ativação, entre em contato com o responsável pelo convite.</p>
    </div>
</body>
</html>
