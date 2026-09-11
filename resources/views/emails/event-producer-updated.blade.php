<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $notificationTitle }}</title>
    <style>
        body,html{margin:0;padding:0}body{background:#081723;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;color:#fff;line-height:1.6}.container{background:#102838;max-width:640px;margin:40px auto;padding:32px;border:1px solid #1bbcff;border-radius:16px;box-shadow:0 12px 32px rgba(0,0,0,.28)}.logo{display:block;margin:0 auto 22px;max-width:130px;height:auto}.eyebrow{text-align:center;text-transform:uppercase;letter-spacing:.12em;font-size:.75rem;font-weight:800;color:#8ce3ff;margin-bottom:8px}h1{font-size:1.8rem;text-align:center;color:#65d7ff;margin:0 0 18px}p{font-size:1rem;color:#f3f8fb}.success{background:rgba(27,188,255,.1);border:1px solid rgba(101,215,255,.32);padding:13px 16px;border-radius:10px;text-align:center;color:#c9f3ff;font-weight:700}.flyer-wrap{margin:24px 0;text-align:center}.flyer-label{margin:0 0 10px;font-size:.86rem;text-transform:uppercase;letter-spacing:.08em;color:#8ce3ff;font-weight:800}.flyer-link{display:block;text-decoration:none}.flyer{display:block;width:100%;max-width:560px;height:auto;margin:0 auto;border-radius:14px;border:1px solid rgba(101,215,255,.28);box-shadow:0 14px 34px rgba(0,0,0,.35)}.context{background:#0b1f2d;border:1px solid rgba(101,215,255,.25);padding:16px 18px;border-radius:12px;margin:22px 0}.context p{margin:7px 0}.context strong{color:#65d7ff}.changes{margin:22px 0;padding:0;list-style:none}.changes li{background:#0b1f2d;margin:9px 0;padding:12px 15px;border-radius:10px;border-left:3px solid #1bbcff}.actions{margin:26px 0 10px}.action{display:block;margin:10px auto;padding:14px 20px;border-radius:10px;background:#1bbcff;color:#071621!important;font-weight:800;text-align:center;text-decoration:none}.action.share{background:#25d366;color:#071621!important}.action.secondary{background:#0b1f2d;color:#65d7ff!important;border:1px solid #1bbcff}.reminder{background:rgba(255,184,77,.09);border:1px solid rgba(255,184,77,.38);padding:16px 18px;border-radius:12px;margin:20px 0}.reminder strong{color:#ffd28a}.checklist{margin:10px 0 0;padding-left:20px;color:#f3f8fb}.checklist li{margin:7px 0}.hint{font-size:.9rem;color:#b9cad3}.footer{text-align:center;padding:18px;font-size:.88rem;color:#b9cad3}@media(max-width:680px){.container{margin:18px;padding:22px}h1{font-size:1.45rem}}
    </style>
</head>
<body>
    <div class="container">
        <img src="https://petertecnet.com.br/petertecnetlogo.png" alt="Peter Tecnet" class="logo" />
        <div class="eyebrow">{{ $appName }}</div>
        <h1>{{ $notificationTitle }}</h1>

        <p>Olá, {{ $owner->name ?: 'produtor' }}.</p>
        <p>{{ $notificationMessage }}</p>

        @if(str_starts_with($action, 'reminder_'))
            <div class="reminder">
                <p><strong>Agora é hora de acompanhar o evento de perto.</strong></p>
                <ul class="checklist">
                    <li>Confira vendas, cortesias, ingressos emitidos e capacidade.</li>
                    <li>Revise equipe, local, horários, check-in e informações do evento.</li>
                    <li>Use o compartilhamento para reforçar a divulgação de última hora.</li>
                    <li>No dia do evento, mantenha a {{ $appName }} aberta para acompanhar a operação e responder rapidamente.</li>
                </ul>
            </div>
        @endif

        @if($action === 'created')
            <p class="success">Seu evento já está pronto para você conferir, divulgar e começar a movimentar as vendas.</p>
        @endif

        @if(!empty($flyerUrl))
            <div class="flyer-wrap">
                <p class="flyer-label">Flyer do seu evento</p>
                <a href="{{ $eventUrl }}" class="flyer-link">
                    <img src="{{ $flyerUrl }}" alt="Flyer de {{ $event->title }}" class="flyer" />
                </a>
            </div>
        @endif

        <div class="context">
            <p><strong>Plataforma:</strong> {{ $appName }}</p>
            <p><strong>Produção:</strong> {{ $production->fantasy ?: $production->name }}</p>
            <p><strong>Evento:</strong> {{ $event->title }}</p>
            @if($event->start_date)
                <p><strong>Início:</strong> {{ $event->start_date->format('d/m/Y H:i') }}</p>
            @endif
            @if($event->venue || $event->city)
                <p><strong>Local:</strong> {{ collect([$event->venue, $event->city])->filter()->implode(' — ') }}</p>
            @endif
            <p><strong>Publicação:</strong> {{ $event->is_published ? 'Publicado' : 'Não publicado' }}</p>
            <p><strong>Situação:</strong> {{ $event->is_cancelled ? 'Cancelado/inativo' : 'Ativo' }}</p>
        </div>

        @if(!empty($changedLabels))
            <p><strong>O que mudou:</strong></p>
            <ul class="changes">
                @foreach($changedLabels as $label)
                    <li>{{ ucfirst($label) }}</li>
                @endforeach
            </ul>
        @endif

        <div class="actions">
            <a href="{{ $eventUrl }}" class="action">{{ str_starts_with($action, 'reminder_') ? 'Abrir meu evento agora' : ($action === 'created' ? 'Ver meu flyer e meu evento' : 'Ver evento na '.$appName) }}</a>
            @if(!empty($shareUrl))
                <a href="{{ $shareUrl }}" class="action share">Compartilhar evento no WhatsApp</a>
            @endif
            <a href="{{ $eventManagementUrl }}" class="action secondary">Gerenciar este evento</a>
            @if($action === 'created' && !empty($createEventUrl))
                <a href="{{ $createEventUrl }}" class="action secondary">Criar meu próximo evento</a>
            @endif
            <a href="{{ $appUrl }}" class="action secondary">Acessar {{ $appName }}</a>
        </div>

        @if($action === 'created')
            <p class="hint">Clique no flyer ou no botão acima para abrir a página pública do evento. O link de compartilhamento leva seu público diretamente para a {{ $appName }}.</p>
        @elseif(str_starts_with($action, 'reminder_'))
            <p class="hint">Este lembrete é enviado uma única vez quando o evento entra na janela das próximas 24 horas. Acesse a {{ $appName }} para acompanhar a operação até o início do evento.</p>
        @else
            <p class="hint">Esta notificação é enviada sempre que o evento sofre uma alteração relevante, inclusive quando a alteração é feita pelo próprio produtor.</p>
        @endif
        <p class="hint">Você recebeu este e-mail porque é o produtor proprietário da produção responsável por este evento. As atualizações também ficam disponíveis nas notificações da plataforma.</p>
    </div>
    <div class="footer">© {{ date('Y') }} Peter Tecnet. Todos os direitos reservados.</div>
</body>
</html>
