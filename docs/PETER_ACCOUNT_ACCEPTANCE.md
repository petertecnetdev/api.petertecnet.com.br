# Critérios de aceite — API Conta Peter Tecnet

- A identidade da conta é global.
- A autorização é avaliada separadamente por aplicativo.
- `/api/account/context` não cria vínculos em `application_user`.
- Handoffs só são emitidos para aplicativos ativos com vínculo autorizado.
- Handoffs expiram em 60 segundos e são consumidos uma única vez.
- O exchange revalida usuário, aplicativo, vínculo e versão de autenticação antes de emitir a sessão de destino.
