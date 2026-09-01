# Deploy da Conta Peter Tecnet

A API deve ser publicada antes dos frontends que consomem o SSO.

Após atualização do código, execute as rotinas normais de produção da API (dependências, migrações, limpeza/cache de configuração e reinício dos workers quando aplicável). Não há nova migração específica para o handoff; os códigos temporários usam o cache configurado da aplicação.

Depois, publique os builds dos frontends Peter Tecnet, Rasoio, Nexus, Plat e Cutinapp.
