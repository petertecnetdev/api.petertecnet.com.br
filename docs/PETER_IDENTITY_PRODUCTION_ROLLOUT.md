# Peter Identity 3.0 — rollout de produção

Ordem obrigatória de publicação:

1. `petertecnet.com.br`: SDK `peter-identity.js` v3, Admin Center e workflow reutilizável com preflight.
2. `api.petertecnet.com.br`: Identity Core v3, migrations, Redis/bootstrap e `identity:preflight --production`.
3. Consumidores: Nexus, Rasoio, Cutinapp, Plat, PayFlow, Laora e Inkap.
4. Ativação gradual do `global_sso` no Admin Center, começando com percentual baixo por aplicação.

O rollout padrão permanece desligado (`IDENTITY_GLOBAL_SSO_ENABLED=false`) até a API e os consumidores estarem publicados e validados.

## Critérios de promoção

- CI da API verde no HEAD final.
- CI do core verde, incluindo E2E real cross-domain.
- CI/build de todos os consumidores verde.
- Deploy da API conclui com `Peter Identity preflight OK`.
- `/api/account/identity/protocol` responde protocolo 3.0.
- Admin `/admin/identity` apresenta Redis disponível ou fallback esperado antes da ativação.
- Teste smoke: login em um app, restauração em outro, logout local preservando o primeiro, logout global impedindo restauração em um terceiro.

## Rollout sugerido

- 5% Nexus por 15–30 min de observação.
- 25% Nexus.
- 100% Nexus.
- Repetir por Rasoio, Cutinapp, Plat, PayFlow, Laora e Inkap.
- Só mudar `IDENTITY_LEGACY_TOKEN_MODE=enforce` quando `legacy.tokens_seen` permanecer zero na janela operacional escolhida.

## Rollback

O primeiro rollback é lógico: desligar `global_sso` ou definir percentual 0 no Admin Center. JWT local ainda válido continua funcionando. Reverter código só é necessário se houver falha fora da camada SSO.
