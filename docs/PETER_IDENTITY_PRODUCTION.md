# Peter Identity — produção

## Princípio arquitetural

`App\Domain\Identity` é o domínio genérico de autenticação da Peter Tecnet. Nexus, Rasoio, Cutinapp, Plat, Inkap, PayFlow, Laora e aplicações futuras consomem o mesmo contrato; não devem criar controllers, services, models ou migrations de autenticação nomeados pelo aplicativo quando a responsabilidade for reutilizável.

## Variáveis recomendadas

```dotenv
IDENTITY_ACCESS_TOKEN_TTL_MINUTES=30
IDENTITY_CACHE_STORE=redis
IDENTITY_GLOBAL_SESSION_TTL_MINUTES=10080
IDENTITY_REFRESH_TTL_MINUTES=43200
IDENTITY_REFRESH_GRACE_SECONDS=30
IDENTITY_CSRF_TTL_SECONDS=300
IDENTITY_REQUIRE_HTTPS_ORIGIN=true

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
```

Senhas e URLs privadas de Redis pertencem somente ao ambiente da VPS e nunca devem ser commitadas.

## Requisitos da VPS

- Redis ativo e acessível pela API;
- extensão `phpredis` compatível com a versão de PHP;
- scheduler Laravel ativo;
- banco de dados acessível durante as migrations;
- HTTPS válido em todos os hosts de produção usados pelo Identity/Passkeys.

## Ordem de deploy

O deploy da API deve executar:

1. `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader`
2. `php artisan optimize:clear`
3. `php artisan migrate --force`
4. `php artisan config:cache`
5. `php artisan identity:preflight --production`
6. `php artisan queue:restart`
7. `php artisan reverb:restart`
8. health check público

Depois da API:

1. publicar o SDK Peter Identity em `petertecnet.com.br`;
2. publicar o Admin Center com gestão de sessões;
3. publicar os gateways dos demais aplicativos;
4. testar login em um app e abertura direta de outro subdomínio sem novo login;
5. testar logout local;
6. testar logout de todo o ecossistema;
7. testar expiração/renovação de um JWT curto;
8. somente depois remover contratos antigos.

## Verificação da VPS

```bash
cd /var/www/api.petertecnet.com.br
php artisan identity:preflight --production
php artisan migrate:status
php artisan schedule:list
redis-cli ping
php -m | grep -i redis
```

Esperado: `redis-cli ping` retorna `PONG` e o preflight termina com `Peter Identity preflight: OK`.

## Modelo de sessão

- `identity_sessions`: sessões/JWT de uma aplicação específica.
- `identity_global_sessions`: autenticação global do navegador no ecossistema.
- JWT nunca é compartilhado entre subdomínios nem colocado na URL.
- cookie global existe somente para o host da API e é `HttpOnly`, `Secure`, host-only e `SameSite=Lax`.
- apenas hashes SHA-256 dos segredos globais são persistidos.
- o refresh global é rotacionado; o segredo anterior só é aceito durante uma janela curta para concorrência legítima entre abas.
- operações baseadas no cookie global exigem `X-Peter-App`, `Origin` HTTPS correspondente à aplicação e `X-Peter-CSRF` vinculado à sessão, aplicação e origem.

## Logout

- **Sair desta plataforma**: revoga somente a sessão/JWT atual da aplicação. A sessão global permanece e pode autenticar outro app.
- **Sair de todas as plataformas**: revoga todas as sessões de aplicação, todas as sessões globais, incrementa `auth_version` e invalida inclusive JWTs antigos/legados ainda não expirados.

## Alteração de rede e risco

Mudança de IP por si só não encerra sessão, porque redes móveis e provedores podem trocar IP frequentemente. Mudança incompatível de família de navegador/plataforma para a mesma sessão global é tratada como contexto de alto risco e exige nova autenticação.

## Redis indisponível após uma release saudável

A sessão global mantém persistência no banco para contingência. O cache Redis continua sendo a camada preferencial e é obrigatório no preflight de produção. Uma falha de Redis após o deploy não deve apagar JWTs locais ainda válidos; restaurar Redis, executar novamente o preflight e revisar a auditoria antes de qualquer revogação em massa.
