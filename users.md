Users

Este documento descreve o funcionamento do domínio User na API PeterTecnet.

Users representam usuários autenticados da API.
Eles são responsáveis por acessar os recursos da API de acordo com as permissões definidas em seu Profile.

Conceito

User é a entidade principal de autenticação

Um User possui um Profile

Um User pode estar vinculado a um ou mais Establishments através da entidade Employer

Users não carregam permissões diretamente, apenas herdam do Profile

Estrutura básica do User

Campos comuns de um usuário:

id

name

email

password

profile_id

created_at

updated_at

O campo password nunca deve ser retornado em respostas da API.

Endpoints
Listar usuários

GET /users

Descrição:
Retorna uma lista de usuários cadastrados, respeitando permissões e paginação.

Obter usuário por ID

GET /users/{id}

Descrição:
Retorna os dados de um usuário específico.

Criar usuário

POST /users

Body:
{
"name": "John Doe",
"email": "john@email.com
",
"password": "secret",
"profile_id": 1
}

Descrição:
Cria um novo usuário e associa a um Profile.

Atualizar usuário

PUT /users/{id}

Body:
{
"name": "John Doe",
"email": "john@email.com
",
"profile_id": 2
}

Descrição:
Atualiza dados do usuário e, se necessário, altera seu Profile.

Excluir usuário

DELETE /users/{id}

Descrição:
Remove um usuário da API.
A exclusão pode ser lógica ou física, conforme regra de negócio.

Usuário autenticado
Obter usuário logado

GET /auth/me

Descrição:
Retorna os dados do usuário autenticado atualmente.

Response:
{
"id": 1,
"name": "John Doe",
"email": "john@email.com
",
"profile": {
"id": 1,
"name": "Admin"
}
}

Relação com Profile

Cada User possui um Profile

O Profile define:

Permissões

Acesso às rotas

A troca de Profile altera imediatamente os acessos do usuário

Exemplo:
User → Profile → Permissions

Relação com Establishments

Users podem estar associados a um ou mais Establishments através da entidade Employer.

Essa relação define:

Em qual establishment o usuário atua

Qual o papel dele naquele contexto

Permissões comuns relacionadas a User

Exemplos de permissões utilizadas neste domínio:

users.read

users.create

users.update

users.delete

Caso o usuário não possua a permissão necessária, a API retornará:
403 Forbidden

Validações comuns

Email deve ser único

Password é obrigatório na criação

Profile deve existir

Campos obrigatórios devem ser validados

Erros de validação retornam status 422 Validation Error.

Boas práticas

Nunca retornar password

Nunca permitir alteração de permissões diretamente pelo User

Gerenciar acesso sempre via Profile

Validar corretamente permissões em rotas sensíveis

Erros comuns

Usuário não encontrado:
{
"message": "User not found"
}
Status: 404 Not Found

Sem permissão:
{
"message": "This action is unauthorized."
}
Status: 403 Forbidden

Documentos relacionados

auth.md – Autenticação

permissions.md – Profiles e permissões

establishments.md – Estabelecimentos

employers.md – Relação usuário ↔ estabelecimento

errors.md – Padrão de erros

README.md – Visão geral da API