Autenticação

Este documento descreve o funcionamento completo do sistema de autenticação da API PeterTecnet.

A API utiliza autenticação baseada em JWT (JSON Web Token), permitindo acesso seguro às rotas protegidas e controle de permissões através de Profiles.

Visão geral

A autenticação é feita via Bearer Token

O token identifica um usuário autenticado

As permissões não ficam no token, mas são resolvidas via Profile

Todas as rotas protegidas exigem autenticação válida

Fluxo de autenticação

O usuário realiza login informando email e senha

A API valida as credenciais

Um token JWT é gerado e retornado

O cliente envia o token em todas as requisições protegidas

A API valida o token e verifica as permissões necessárias

Login

Endpoint:
POST /auth/login

Body:
{
"email": "user@email.com
",
"password": "secret"
}

Response (200):
{
"token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
}

Erros possíveis:

401 Unauthorized – email ou senha inválidos

422 Validation Error – campos obrigatórios ausentes

Uso do token

Após o login, o token deve ser enviado no header Authorization em todas as requisições protegidas.

Header obrigatório:
Authorization: Bearer {token}

Exemplo:
GET /orders
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...

Expiração do token

Tokens possuem tempo de expiração configurável

Após expirar, o token não é mais aceito

Requisições com token expirado retornam 401 Unauthorized

O cliente deve tratar esse erro e solicitar novo login.

Refresh token (opcional / futuro)

Caso a API implemente refresh token no futuro, o fluxo esperado é:

O cliente envia o refresh token

A API valida o refresh token

Um novo token JWT é gerado

Endpoint esperado:
POST /auth/refresh

Response:
{
"token": "novo.jwt.token"
}

Logout

Endpoint:
POST /auth/logout

Descrição:
Invalida o token atual do usuário, encerrando a sessão.

Response (200):
{
"message": "Logout successful"
}

Usuário autenticado

Endpoint:
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

Autorização e permissões

Autenticação e autorização são conceitos diferentes.

Autenticação valida quem é o usuário

Autorização valida o que o usuário pode fazer

As permissões são associadas ao Profile do usuário.

Exemplo de permissão:
events.create

Se o usuário não possuir a permissão exigida pela rota, a API retornará:
403 Forbidden

Middleware de autenticação

Rotas protegidas utilizam middleware de autenticação.

Comportamento:

Token ausente → 401 Unauthorized

Token inválido → 401 Unauthorized

Sem permissão → 403 Forbidden

Erros comuns de autenticação

Token ausente:
{
"message": "Unauthenticated."
}
Status: 401 Unauthorized

Token inválido ou expirado:
{
"message": "Token is invalid or expired."
}
Status: 401 Unauthorized

Usuário sem permissão:
{
"message": "This action is unauthorized."
}
Status: 403 Forbidden

Recuperação de senha (opcional / futuro)

Fluxo esperado para recuperação de senha:

Usuário solicita recuperação informando email

A API envia um link ou código

Usuário redefine a senha

Endpoints esperados:
POST /auth/forgot-password
POST /auth/reset-password

Esses endpoints devem ser protegidos contra abuso.

Boas práticas de segurança

Nunca exponha tokens em URLs

Nunca armazene tokens em local inseguro

Utilize HTTPS em produção

Trate corretamente erros 401 e 403 no cliente

Revogue tokens quando necessário

Relacionamentos importantes

User

autentica na API

possui um Profile

Profile

define permissões

controla acesso às rotas

Documentos relacionados

permissions.md – Controle de acesso e permissões

errors.md – Padrão de erros da API

README.md – Visão geral da API