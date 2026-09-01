# Contrato dos frontends

Os frontends usam `GET /api/account/ecosystem` para montar o launcher. Para navegar entre produtos, solicitam `POST /api/account/sso/handoff` e enviam somente `peter_sso` ao host de destino. O destino chama `POST /api/account/sso/exchange` antes de inicializar sua autenticação normal.
