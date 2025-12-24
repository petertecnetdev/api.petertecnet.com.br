Conventions

Este documento define as convenções e padrões globais da API PeterTecnet.

O objetivo destas convenções é garantir consistência, previsibilidade e facilidade de uso da API por diferentes aplicações e desenvolvedores.

Padrão geral da API

A API segue o padrão REST

Todas as respostas são em JSON

Todas as requisições devem usar UTF-8

A API é stateless

Autenticação é feita via Bearer Token (JWT)

URLs e versionamento

As rotas seguem o padrão plural:

/users

/establishments

/orders

/events

O versionamento pode ser aplicado no futuro:

/v1/users

/v2/users

Enquanto não houver versionamento explícito, considera-se a versão atual como estável.

Métodos HTTP

Uso padrão dos métodos HTTP:

GET – listar ou obter recursos

POST – criar recursos

PUT – atualizar recursos

DELETE – remover ou desativar recursos

Não utilizar métodos fora desse padrão para ações comuns.

Headers padrão

Todas as requisições devem enviar:

Accept: application/json
Content-Type: application/json

Requisições autenticadas devem enviar:

Authorization: Bearer {token}

Paginação

Endpoints que retornam listas devem suportar paginação.

Parâmetros padrão:

page – número da página

per_page – quantidade de registros por página

Exemplo:
?page=1&per_page=10

A resposta deve conter apenas os dados da página solicitada.

Filtros e ordenação

Quando aplicável, endpoints podem aceitar filtros via query string.

Exemplo:
GET /orders?status=paid

Ordenação pode ser feita com parâmetros como:

sort

order

Exemplo:
GET /orders?sort=created_at&order=desc

Datas e horários

Todas as datas devem seguir o padrão ISO 8601

Timezone deve ser tratado de forma consistente no backend

Exemplos:

2025-10-01

2025-10-01 20:00:00

Status HTTP

A API deve utilizar corretamente os códigos HTTP:

200 OK – requisição bem-sucedida

201 Created – recurso criado

400 Bad Request – erro de requisição

401 Unauthorized – não autenticado

403 Forbidden – sem permissão

404 Not Found – recurso não encontrado

422 Validation Error – erro de validação

500 Internal Server Error – erro interno

Formato padrão de respostas
Resposta de sucesso (exemplo)

{
"data": {
"id": 1,
"name": "Exemplo"
}
}

A estrutura pode variar, mas deve manter consistência entre endpoints.

Formato padrão de erros

Todos os erros devem seguir o mesmo formato.

Exemplo:
{
"message": "Validation error",
"errors": {
"field": ["Mensagem de erro"]
}
}

Validações

Campos obrigatórios devem ser validados

Erros de validação retornam status 422

Mensagens de erro devem ser claras e objetivas

Controle de acesso

Antes de executar qualquer ação, a API deve validar:

Usuário autenticado

Permissão necessária (Profile)

Contexto válido (Employer / Establishment)

Falha em qualquer etapa deve interromper a execução.

Exclusão de recursos

Sempre que possível:

Preferir exclusão lógica (soft delete)

Manter histórico e integridade de dados

A exclusão física deve ser usada apenas quando fizer sentido no domínio.

Boas práticas gerais

Nunca confiar em dados enviados pelo cliente

Centralizar regras de negócio no backend

Não expor dados sensíveis

Validar permissões em todas as rotas sensíveis

Manter consistência entre endpoints semelhantes

Evolução e manutenção

Novas rotas devem seguir estas convenções

Mudanças devem ser documentadas

Evitar quebra de compatibilidade sempre que possível

Documentos relacionados

auth.md – Autenticação

permissions.md – Permissões

errors.md – Padrão de erros

README.md – Visão geral da API