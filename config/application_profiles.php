<?php

return [
    'applications' => [
        'cutinapp' => [
            'profiles' => [
                'producer' => ['name' => 'Produtor', 'description' => 'Cria e administra produções, eventos, ingressos, vendas e equipe.', 'permissions' => ['production.view','production.create','production.update','production.delete','production.media.manage','production.team.manage','event.view','event.create','event.update','event.publish','event.delete','ticket.manage','order.view','finance.view','checkin.manage','complimentary.manage','event.metrics.view','production.content.publish']],
                'promoter' => ['name' => 'Promoter', 'description' => 'Divulga eventos autorizados e acompanha resultados atribuídos.', 'permissions' => ['promotion.manage','promotion.metrics.view','guestlist.manage','complimentary.issue_authorized','event.materials.view']],
                'artist' => ['name' => 'Artista', 'description' => 'Administra o perfil artístico, agenda, conteúdos e participações.', 'permissions' => ['artist.profile.manage','artist.events.view','artist.content.manage','artist.invites.respond','artist.media.manage']],
                'checkin_operator' => ['name' => 'Operador de check-in', 'description' => 'Opera entrada e validação de ingressos sem acesso financeiro.', 'permissions' => ['checkin.view','checkin.manage','ticket.lookup','attendee.list.view']],
                'production_manager' => ['name' => 'Gerente de produção', 'description' => 'Administra produção e eventos autorizados sem assumir propriedade financeira.', 'permissions' => ['production.view','production.update','production.media.manage','event.view','event.create','event.update','event.publish','ticket.manage','checkin.manage','guestlist.manage','event.metrics.view']],
            ],
        ],
        'rasoio' => [
            'profiles' => [
                'owner' => ['name' => 'Proprietário', 'description' => 'Administração completa do estabelecimento no Rasoio.', 'permissions' => ['establishment.manage','service.manage','team.manage','schedule.manage','client.manage','finance.view','reports.view','settings.manage','subscription.manage']],
                'manager' => ['name' => 'Gerente', 'description' => 'Administra a operação delegada pelo proprietário.', 'permissions' => ['service.manage','team.manage','schedule.manage','client.manage','reports.view','settings.manage_limited']],
                'professional' => ['name' => 'Colaborador / Profissional', 'description' => 'Gerencia a própria agenda, disponibilidade e atendimentos.', 'permissions' => ['schedule.own.view','schedule.own.manage','availability.own.manage','appointment.own.manage','client.related.view']],
                'reception' => ['name' => 'Recepção', 'description' => 'Gerencia agendamentos e clientes da operação.', 'permissions' => ['schedule.view','appointment.manage','client.manage','service.view','professional.view']],
            ],
        ],
        'nexus' => [
            'profiles' => [
                'owner' => ['name' => 'Proprietário da empresa', 'description' => 'Administração completa da empresa e do catálogo.', 'permissions' => ['company.manage','unit.manage','catalog.manage','product.manage','category.manage','price.manage','stock.manage','order.manage','payment.manage','qr.manage','team.manage','finance.view','reports.view','settings.manage']],
                'manager' => ['name' => 'Gerente', 'description' => 'Administra operação, produtos, estoque, pedidos e equipe.', 'permissions' => ['catalog.manage','product.manage','stock.manage','order.manage','client.manage','team.manage','reports.view']],
                'operator' => ['name' => 'Atendente / Operador', 'description' => 'Opera pedidos, retirada e entrega.', 'permissions' => ['order.view','order.update_status','fulfillment.manage','client.view']],
                'stock' => ['name' => 'Estoque', 'description' => 'Gerencia disponibilidade e movimentações de estoque.', 'permissions' => ['product.view','stock.view','stock.manage']],
                'cashier' => ['name' => 'Caixa', 'description' => 'Opera pedidos, recebimentos e fechamento.', 'permissions' => ['order.view','payment.manage','refund.authorized','cashier.close']],
            ],
        ],
        'plat' => [
            'profiles' => [
                'owner' => ['name' => 'Proprietário', 'description' => 'Administração completa do estabelecimento no Plat.', 'permissions' => ['establishment.manage','product.manage','team.manage','order.manage','finance.view','reports.view','settings.manage','stock.manage']],
                'manager' => ['name' => 'Gerente', 'description' => 'Administra a operação delegada.', 'permissions' => ['product.manage','team.manage','order.manage','stock.manage','reports.view']],
                'cashier' => ['name' => 'Caixa', 'description' => 'Opera pagamentos e fechamento.', 'permissions' => ['order.view','payment.manage','cashier.close','refund.authorized']],
                'attendant' => ['name' => 'Atendente', 'description' => 'Abre e atualiza pedidos, mesas e comandas.', 'permissions' => ['order.create','order.update','client.view','table.manage']],
                'kitchen' => ['name' => 'Produção / Cozinha', 'description' => 'Opera a fila de preparação dos pedidos.', 'permissions' => ['order.production.view','order.production.update_status']],
                'stock' => ['name' => 'Estoque', 'description' => 'Gerencia produtos, insumos e disponibilidade.', 'permissions' => ['product.view','stock.view','stock.manage']],
            ],
        ],
        'payflow' => [
            'profiles' => [
                'owner' => ['name' => 'Proprietário da conta', 'description' => 'Acesso completo à organização do Payflow.', 'permissions' => ['organization.manage','team.manage','client.manage','opportunity.manage_all','proposal.manage_all','charge.manage','finance.view','reports.view','settings.manage']],
                'sales_manager' => ['name' => 'Gerente comercial', 'description' => 'Administra clientes, equipe, pipeline e propostas.', 'permissions' => ['client.manage','opportunity.manage_all','proposal.manage_all','pipeline.manage','sales.assign','sales.reports.view']],
                'sales' => ['name' => 'Vendedor / Comercial', 'description' => 'Gerencia sua carteira, oportunidades e propostas.', 'permissions' => ['client.own.manage','opportunity.own.manage','proposal.own.manage','activity.own.manage','followup.own.manage']],
                'finance' => ['name' => 'Financeiro', 'description' => 'Administra cobranças, pagamentos e conciliação.', 'permissions' => ['charge.manage','payment.manage','delinquency.manage','receipt.manage','finance.view','reconciliation.manage']],
                'operations' => ['name' => 'Atendimento / Operação', 'description' => 'Consulta clientes e negociações e registra atividades permitidas.', 'permissions' => ['client.view','opportunity.view','proposal.view','activity.manage']],
            ],
        ],
        'locaio' => [
            'profiles' => [
                'owner' => ['name' => 'Proprietário', 'description' => 'Administração completa do negócio no Locaio.', 'permissions' => ['business.manage','service.manage','professional.manage','schedule.manage','client.manage','billing.manage','team.manage','reports.view','settings.manage']],
                'manager' => ['name' => 'Gerente', 'description' => 'Administra operação, equipe, agenda, serviços e clientes.', 'permissions' => ['service.manage','professional.manage','schedule.manage','client.manage','reports.view']],
                'professional' => ['name' => 'Prestador / Profissional', 'description' => 'Administra agenda, disponibilidade e serviços atribuídos.', 'permissions' => ['schedule.own.view','schedule.own.manage','availability.own.manage','service.assigned.view','appointment.own.manage','client.related.view']],
                'reception' => ['name' => 'Recepção / Atendente', 'description' => 'Administra agenda e cadastro operacional de clientes.', 'permissions' => ['schedule.view','appointment.manage','client.manage','professional.view']],
            ],
        ],
        'laora' => [
            'profiles' => [
                'owner' => ['name' => 'Proprietário', 'description' => 'Administração completa da conta e da assinatura.', 'permissions' => ['account.manage','subscription.manage','team.manage','settings.manage','operations.manage']],
                'manager' => ['name' => 'Gerente', 'description' => 'Administra recursos operacionais e equipe delegada.', 'permissions' => ['team.manage','settings.manage_limited','operations.manage','reports.view']],
                'collaborator' => ['name' => 'Colaborador', 'description' => 'Utiliza os recursos operacionais autorizados.', 'permissions' => ['operations.use']],
            ],
        ],
        'prevora' => [
            'profiles' => [
                'validator' => ['name' => 'Analista / Validador', 'description' => 'Analisa previsões, evidências e resultados.', 'permissions' => ['prediction.analyze','evidence.manage','occurrence.track','validation.propose']],
                'moderator' => ['name' => 'Moderador', 'description' => 'Modera previsões, comentários e denúncias.', 'permissions' => ['prediction.moderate','comment.moderate','report.moderate']],
                'curator' => ['name' => 'Curador', 'description' => 'Organiza destaques, categorias e conteúdo editorial.', 'permissions' => ['prediction.feature','category.manage','editorial.manage']],
            ],
        ],
        'peter-tecnet' => [
            'profiles' => [
                'super_admin' => ['name' => 'Super Admin', 'description' => 'Administração integral do ecossistema.', 'permissions' => ['ecosystem.manage','users.manage','profiles.manage','permissions.manage','establishments.manage','content.moderate','finance.manage','support.manage','audit.view','security.manage','applications.manage']],
                'users_admin' => ['name' => 'Administrador de usuários', 'description' => 'Administra usuários, convites, perfis e acessos.', 'permissions' => ['users.manage','profiles.manage','permissions.manage','user_access.manage']],
                'establishments_admin' => ['name' => 'Administrador de estabelecimentos', 'description' => 'Administra empresas, estabelecimentos, produções e recursos comerciais.', 'permissions' => ['establishments.manage','productions.manage','events.manage']],
                'moderator' => ['name' => 'Moderador', 'description' => 'Modera conteúdo, publicações, eventos e denúncias.', 'permissions' => ['content.moderate','reports.moderate','events.moderate']],
                'finance' => ['name' => 'Financeiro', 'description' => 'Administra pagamentos, cobranças, repasses e relatórios financeiros.', 'permissions' => ['finance.manage','payments.manage','payouts.manage','finance.reports.view']],
                'support' => ['name' => 'Suporte', 'description' => 'Consulta usuários e recursos e executa ações de suporte autorizadas.', 'permissions' => ['support.manage','users.view','establishments.view','impersonation.authorized']],
                'auditor' => ['name' => 'Auditor', 'description' => 'Acesso somente leitura a auditoria, interações e operações.', 'permissions' => ['audit.view','interactions.view','operations.view','security.view']],
            ],
        ],
    ],
    'permission_aliases' => [
        'production_show' => ['production.view'],
        'production_create' => ['production.create'],
        'production_edit' => ['production.update'],
        'production_update' => ['production.update'],
        'production_delete' => ['production.delete'],
        'production_config' => ['production.team.manage','production.media.manage'],
        'event_create' => ['event.create'],
        'event_edit' => ['event.update'],
        'event_update' => ['event.update'],
        'event_delete' => ['event.delete'],
        'event_config' => ['event.update'],
        'ticket_create' => ['ticket.manage'],
        'ticket_edit' => ['ticket.manage'],
        'ticket_update' => ['ticket.manage'],
        'ticket_delete' => ['ticket.manage'],
        'ticket_checkin' => ['checkin.manage'],
        'event_checkin' => ['checkin.manage'],
    ],
];
