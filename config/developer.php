<?php

return [
    'default_rate_limit_per_minute' => (int) env('DEVELOPER_API_RATE_LIMIT', 60),
    'max_rate_limit_per_minute' => (int) env('DEVELOPER_API_MAX_RATE_LIMIT', 600),
    'max_per_page' => 100,
    'deprecation_notice_days' => 90,

    'scopes' => [
        'establishments:read' => 'Consultar estabelecimentos publicados.',
        'catalog:read' => 'Consultar produtos e serviços públicos.',
        'events:read' => 'Consultar eventos e ingressos públicos.',
        'content:read' => 'Consultar conteúdo público do ecossistema.',
        'orders:read' => 'Consultar pedidos autorizados ao cliente da API.',
        'orders:write' => 'Criar e atualizar pedidos autorizados.',
        'appointments:read' => 'Consultar agendamentos autorizados.',
        'appointments:write' => 'Criar e atualizar agendamentos autorizados.',
        'webhooks:manage' => 'Gerenciar endpoints e eventos de webhook.',
    ],

    'webhook_events' => [
        'developer.webhook.test',
        'order.created',
        'order.updated',
        'payment.paid',
        'payment.failed',
        'appointment.created',
        'appointment.confirmed',
        'appointment.cancelled',
        'ticket.checked_in',
    ],
];
