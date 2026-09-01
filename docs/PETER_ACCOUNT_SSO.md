# Conta Peter Tecnet e SSO do ecossistema

A identidade do usuário é global, enquanto autorização e dados de negócio permanecem específicos por aplicativo.

## Endpoints

- `GET /api/account/ecosystem`: identidade da conta e catálogo de aplicativos com estado de acesso.
- `POST /api/account/sso/handoff`: cria um código temporário de passagem para um aplicativo autorizado.
- `POST /api/account/sso/exchange`: consome o código uma única vez e entrega uma sessão própria do aplicativo de destino.

O handoff expira em 60 segundos, é vinculado ao usuário, ao aplicativo e à versão de autenticação. A consulta de `/api/account/context` é somente leitura e não concede acesso a aplicativos.
