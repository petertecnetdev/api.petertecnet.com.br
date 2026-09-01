# Segurança

O JWT principal não trafega em query string. O handoff é aleatório, armazenado pelo hash, vinculado ao usuário e aplicativo, expira em 60 segundos e é removido do cache no primeiro exchange. O exchange revalida `auth_version` e membership antes de emitir uma nova sessão.
