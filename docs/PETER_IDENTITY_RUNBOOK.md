# Peter Identity Core — Runbook de produção

## Objetivo

O Peter Identity é o domínio genérico de autenticação e sessões de todo o ecossistema Peter Tecnet. Nenhuma aplicação deve manter uma implementação de SSO própria quando a responsabilidade puder ser atendida por este módulo.

## Configuração recomendada de produção

```dotenv
IDENTITY_CACHE_STORE=redis
IDENTITY_SESSION_TTL_MINUTES=10080
IDENTITY_REFRESH_TTL_MINUTES=43200
IDENTITY_REFRESH_GRACE_SECONDS=30
IDENTITY_CSRF_TTL_SECONDS=300
IDENTITY_ACCESS_TOKEN_TTL_MINUTES=30
IDENTITY_REQUIRE_HTTPS_ORIGIN=true
IDENTITY_AUDIT_RETENTION_DAYS=180

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
```

Não versionar senha de Redis ou qualquer outra credencial. Quando houver senha, configure `REDIS_PASSWORD` diretamente no ambiente da VPS.

## Requisitos da VPS

- Redis disponível e iniciado.
- extensão PHP Redis (`phpredis`) compatível com a versão de PHP em uso;
- API capaz de conectar ao store `redis` configurado pelo Laravel;
- scheduler Laravel ativo para executar a poda diária dos eventos de autenticação;
- banco disponível para as tabelas persistentes de Identity.

## Deploy

A migration cria as tabelas genéricas:

- `identity_devices`
- `identity_sessions`
- `identity_auth_events`

O deploy Laravel deve executar, nesta ordem:

1. `composer install --no-dev --optimize-autoloader`
2. `php artisan optimize:clear`
3. `php artisan migrate --force`
4. `php artisan config:cache`
5. `php artisan identity:preflight --production`
6. restart de filas/Reverb
7. health check HTTP

O preflight bloqueia a publicação caso Redis não esteja realmente funcional, alguma tabela do Identity esteja ausente ou uma proteção obrigatória de produção esteja desabilitada.

## Verificação manual segura

```bash
cd /var/www/api.petertecnet.com.br
php artisan identity:preflight --production
php artisan migrate:status
php artisan schedule:list
redis-cli ping
php -m | grep -i redis
```

O resultado esperado do Redis é `PONG` e o comando de preflight deve terminar com `Peter Identity preflight: OK`.

## Política de tokens e sessões

- JWT é emitido por aplicação; nunca é colocado em query string ou cookie compartilhado entre subdomínios.
- A sessão global usa identificadores aleatórios opacos em cookies `HttpOnly`, `Secure`, host-only da API e `SameSite=Lax`.
- Apenas hashes dos segredos de sessão/refresh são persistidos.
- Refresh é rotacionado; o segredo anterior possui janela curta de tolerância para concorrência legítima entre abas.
- `auth_version` permite invalidar JWTs previamente emitidos após eventos globais de segurança.
- Logout local encerra o JWT da aplicação atual sem derrubar a sessão global.
- Logout global revoga todas as sessões Identity e incrementa `auth_version`.

## CSRF e origem

Operações baseadas no cookie global exigem correspondência entre:

- `X-Peter-App`;
- aplicação ativa registrada;
- `Origin` HTTPS correspondente ao host configurado para a aplicação;
- desafio `X-Peter-CSRF` assinado e vinculado à sessão, aplicação e origem.

Nunca desabilitar `IDENTITY_REQUIRE_HTTPS_ORIGIN` em produção para contornar erro de configuração de domínio.

## Redis indisponível depois do deploy

O serviço possui fallback persistente no banco para preservar disponibilidade de sessões já criadas. Isso é apenas contingência. O preflight de produção exige Redis funcional antes de uma nova release do Identity ser aceita.

Se Redis cair após a publicação:

1. não apagar JWTs locais válidos;
2. restaurar Redis;
3. executar `php artisan identity:preflight --production`;
4. verificar eventos `identity_auth_events` para falhas anormais;
5. não fazer logout global em massa sem evidência de comprometimento.

## Ordem de rollout do ecossistema

1. API Peter Tecnet / Peter Identity;
2. SDK central hospedado em `petertecnet.com.br`;
3. Admin Center;
4. gateways dos demais aplicativos;
5. smoke test autenticado entre pelo menos duas aplicações distintas;
6. somente depois remover compatibilidades antigas.

Essa ordem impede que um frontend passe a depender de `/identity/v1` antes de a API correspondente estar em produção.
