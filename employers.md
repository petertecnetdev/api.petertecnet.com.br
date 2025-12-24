Employers

Este documento descreve o domínio Employer na API PeterTecnet.

Employer representa a relação entre um User e um Establishment.
Ele define vínculo, contexto e papel do usuário dentro de um establishment.

Conceito

Employer não é um usuário

Employer não é um establishment

Employer é a entidade de ligação entre User e Establishment

Um User pode ter vários Employers

Um Establishment pode ter vários Employers

Essa estrutura permite que um mesmo usuário atue em múltiplos establishments com funções diferentes.

Estrutura básica do Employer

Campos comuns de um employer:

id

user_id

establishment_id

role (opcional, dependendo da regra de negócio)

active

created_at

updated_at

O campo role pode representar a função do usuário dentro do establishment, caso aplicável.

Endpoints
Listar employers

GET /employers

Descrição:
Retorna os vínculos entre usuários e establishments acessíveis ao usuário autenticado.

Criar vínculo (associar usuário ao establishment)

POST /employers

Body:
{
"user_id": 1,
"establishment_id": 2
}

Descrição:
Cria um vínculo entre um usuário e um establishment, permitindo que o usuário atue naquele contexto.

Remover vínculo

DELETE /employers/{id}

Descrição:
Remove ou desativa o vínculo entre o usuário e o establishment.

Relação com User

Um User pode ter múltiplos Employers

O vínculo define em quais establishments o usuário pode atuar

As permissões globais continuam sendo controladas pelo Profile

Exemplo:
User → Employer → Establishment

Relação com Establishment

Um Establishment pode ter vários Employers

Employers determinam quem pode operar o establishment

A remoção de um Employer impede o acesso do usuário àquele establishment

Permissões comuns relacionadas a Employer

Exemplos de permissões utilizadas neste domínio:

employers.read

employers.create

employers.delete

Usuários sem a permissão necessária receberão:
403 Forbidden

Regras de negócio importantes

Um usuário só pode acessar dados de um establishment se possuir um Employer ativo

A exclusão de um Employer pode ser lógica (active = false)

Não deve existir Employer duplicado para o mesmo user e establishment

Apenas usuários autorizados podem criar ou remover vínculos

Validações comuns

user_id deve existir

establishment_id deve existir

O vínculo não pode ser duplicado

Apenas usuários com permissão podem criar ou remover employers

Erros de validação retornam status 422 Validation Error.

Boas práticas

Utilizar Employer para todo vínculo usuário ↔ establishment

Evitar lógica direta User → Establishment sem Employer

Centralizar validações de acesso usando Employer

Usar Employer como base para filtros de dados por establishment

Erros comuns

Vínculo não encontrado:
{
"message": "Employer not found"
}
Status: 404 Not Found

Sem permissão:
{
"message": "This action is unauthorized."
}
Status: 403 Forbidden

Documentos relacionados

users.md – Usuários

establishments.md – Estabelecimentos

permissions.md – Profiles e permissões

errors.md – Padrão de erros

README.md – Visão geral da API