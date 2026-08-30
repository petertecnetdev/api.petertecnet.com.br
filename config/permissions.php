<?php

return [
    'ecosystem_manage' => [
        'category' => 'Sistema',
        'name' => 'Administrar ecossistema',
        'description' => 'Permite acessar a central Peter Tecnet, usuários, atividade, segurança, aplicações e governança do ecossistema.',
    ],
    'application_manage' => [
        'category' => 'Aplicações',
        'name' => 'Gerenciar aplicações',
        'description' => 'Permite cadastrar, editar, ativar e excluir aplicações da Peter Tecnet.',
    ],

    'marketing_dashboard' => [
        'category' => 'Marketing',
        'name' => 'Acessar painel de marketing',
        'description' => 'Permite acompanhar indicadores das aplicações atribuídas ao colaborador.',
    ],
    'marketing_activity_view' => [
        'category' => 'Marketing',
        'name' => 'Visualizar atividade de marketing',
        'description' => 'Permite consultar interações e evolução de clientes somente nas aplicações atribuídas.',
    ],
    'marketing_user_view' => [
        'category' => 'Marketing',
        'name' => 'Visualizar clientes da aplicação',
        'description' => 'Permite consultar usuários vinculados às aplicações atribuídas.',
    ],
    'marketing_user_invite' => [
        'category' => 'Marketing',
        'name' => 'Convidar clientes',
        'description' => 'Permite criar convites de acesso somente para aplicações atribuídas.',
    ],

    'item_scan' => [
        'category' => 'Item',
        'name' => 'Escanear item',
        'description' => 'Permite ao usuário escanear itens.',
    ],
    'item_scam' => [
        'category' => 'Item',
        'name' => 'Escanear item (legado)',
        'description' => 'Alias legado da permissão item_scan.',
    ],
    'item_check' => [
        'category' => 'Item',
        'name' => 'Validar item',
        'description' => 'Permite ao usuário validar itens.',
    ],
    'Item_check' => [
        'category' => 'Item',
        'name' => 'Validar item (legado)',
        'description' => 'Alias legado da permissão item_check.',
    ],
    'item_list' => ['category' => 'Item', 'name' => 'Listar itens', 'description' => 'Permite ao usuário listar itens.'],
    'item_config' => ['category' => 'Item', 'name' => 'Configurar item', 'description' => 'Permite ao usuário configurar itens.'],
    'item_create' => ['category' => 'Item', 'name' => 'Criar item', 'description' => 'Permite ao usuário criar itens.'],
    'item_edit' => ['category' => 'Item', 'name' => 'Editar item', 'description' => 'Permite ao usuário editar itens.'],
    'item_delete' => ['category' => 'Item', 'name' => 'Excluir item', 'description' => 'Permite ao usuário excluir itens.'],

    'user_list' => ['category' => 'Usuário', 'name' => 'Listar usuários', 'description' => 'Permite ao usuário listar usuários.'],
    'user_create' => ['category' => 'Usuário', 'name' => 'Criar usuário', 'description' => 'Permite ao usuário cadastrar outros usuários.'],
    'user_show' => ['category' => 'Usuário', 'name' => 'Ver usuário específico', 'description' => 'Permite ao usuário ver detalhes de um usuário específico.'],
    'user_edit' => ['category' => 'Usuário', 'name' => 'Editar usuário', 'description' => 'Permite ao usuário editar informações de um usuário existente.'],
    'user_delete' => ['category' => 'Usuário', 'name' => 'Excluir usuário', 'description' => 'Permite ao usuário excluir um usuário.'],
    'user_config' => ['category' => 'Usuário', 'name' => 'Configurar usuário', 'description' => 'Permite ao usuário configurar opções de um usuário.'],
    'user_management' => ['category' => 'Usuário', 'name' => 'Gerenciamento de usuários', 'description' => 'Permite ao usuário gerenciar usuários e perfis.'],

    'event_config' => ['category' => 'Evento', 'name' => 'Configurar evento', 'description' => 'Permite ao usuário configurar eventos.'],
    'event_create' => ['category' => 'Evento', 'name' => 'Cadastrar evento', 'description' => 'Permite ao usuário cadastrar um novo evento.'],
    'event_edit' => ['category' => 'Evento', 'name' => 'Editar evento', 'description' => 'Permite ao usuário editar informações de um evento existente.'],
    'event_delete' => ['category' => 'Evento', 'name' => 'Excluir evento', 'description' => 'Permite ao usuário excluir um evento.'],

    'ticket_create' => ['category' => 'Ingresso', 'name' => 'Cadastrar ingresso', 'description' => 'Permite cadastrar ingressos para um evento.'],
    'ticket_edit' => ['category' => 'Ingresso', 'name' => 'Editar ingresso', 'description' => 'Permite editar informações de ingressos.'],
    'ticket_delete' => ['category' => 'Ingresso', 'name' => 'Excluir ingresso', 'description' => 'Permite excluir ingressos.'],

    'service_record_store' => ['category' => 'Atendimentos', 'name' => 'Registrar atendimento', 'description' => 'Permite registrar um atendimento ou prestação de serviço.'],
    'service_record_list' => ['category' => 'Atendimentos', 'name' => 'Listar atendimentos', 'description' => 'Permite consultar atendimentos de clientes, prestadores e entidades.'],
    'service_record_update' => ['category' => 'Atendimentos', 'name' => 'Atualizar atendimento', 'description' => 'Permite alterar o status de atendimentos.'],
    'service_record_delete' => ['category' => 'Atendimentos', 'name' => 'Excluir atendimento', 'description' => 'Permite excluir atendimentos.'],

    'promoter_create' => ['category' => 'Promoter', 'name' => 'Cadastrar promoter', 'description' => 'Permite cadastrar promoters em eventos.'],
    'promoter_edit' => ['category' => 'Promoter', 'name' => 'Editar promoter', 'description' => 'Permite editar promoters.'],
    'promoter_delete' => ['category' => 'Promoter', 'name' => 'Excluir promoter', 'description' => 'Permite excluir promoters associados a eventos.'],

    'profile_create' => ['category' => 'Perfil', 'name' => 'Criar perfil', 'description' => 'Permite criar um novo perfil.'],
    'profile_view' => ['category' => 'Perfil', 'name' => 'Ver perfis', 'description' => 'Permite visualizar perfis.'],
    'profile_show' => ['category' => 'Perfil', 'name' => 'Ver perfil específico', 'description' => 'Permite visualizar um perfil específico.'],
    'profile_delete' => ['category' => 'Perfil', 'name' => 'Excluir perfil', 'description' => 'Permite excluir perfis.'],
    'profile_edit' => ['category' => 'Perfil', 'name' => 'Editar perfil', 'description' => 'Permite editar perfis.'],

    'permission_management' => ['category' => 'Sistema', 'name' => 'Gerenciamento de permissões', 'description' => 'Permite gerenciar as permissões dos perfis.'],
    'role_management' => ['category' => 'Sistema', 'name' => 'Gerenciamento de papéis', 'description' => 'Permite gerenciar papéis e suas permissões.'],

    'report_view' => ['category' => 'Relatório', 'name' => 'Visualizar relatórios', 'description' => 'Permite visualizar relatórios e estatísticas.'],
    'report_generate' => ['category' => 'Relatório', 'name' => 'Gerar relatórios', 'description' => 'Permite gerar relatórios e estatísticas.'],

    'blog_create' => ['category' => 'Blog', 'name' => 'Criar blog', 'description' => 'Permite criar conteúdo de blog.'],
    'blog_view' => ['category' => 'Blog', 'name' => 'Ver blogs', 'description' => 'Permite visualizar blogs.'],
    'blog_show' => ['category' => 'Blog', 'name' => 'Ver blog específico', 'description' => 'Permite visualizar um blog específico.'],
    'blog_edit' => ['category' => 'Blog', 'name' => 'Editar blog', 'description' => 'Permite editar blogs.'],
    'blog_delete' => ['category' => 'Blog', 'name' => 'Excluir blog', 'description' => 'Permite excluir blogs.'],

    'production_create' => ['category' => 'Produção', 'name' => 'Cadastrar produção', 'description' => 'Permite cadastrar uma nova produção.'],
    'production_edit' => ['category' => 'Produção', 'name' => 'Editar produção', 'description' => 'Permite editar uma produção existente.'],
    'production_delete' => ['category' => 'Produção', 'name' => 'Excluir produção', 'description' => 'Permite excluir uma produção.'],
    'production_config' => ['category' => 'Produção', 'name' => 'Configurar produção', 'description' => 'Permite configurar uma produção.'],
    'production_show' => ['category' => 'Produção', 'name' => 'Ver produção específica', 'description' => 'Permite visualizar detalhes de uma produção.'],
];
