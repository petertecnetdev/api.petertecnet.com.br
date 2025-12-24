Establishments

Este documento descreve o domínio Establishment na API PeterTecnet.

Establishments representam empresas, organizações ou negócios que utilizam a plataforma.
Eles são o núcleo de vários outros domínios, como usuários, pedidos e eventos.

Conceito

Um Establishment representa uma entidade de negócio

Pode ser uma empresa, loja, organizador de eventos, etc.

Um Establishment pode ter vários usuários vinculados

Um Establishment pode possuir pedidos, eventos e itens

A relação entre User e Establishment é feita via Employer

Estrutura básica do Establishment

Campos comuns de um establishment:

id

name

description

document (CNPJ / identificador)

email

phone

active

created_at

updated_at

Os campos podem variar conforme a necessidade do negócio.

Endpoints
Listar establishments

GET /establishments

Descrição:
Retorna uma lista de establishments acessíveis ao usuário autenticado, respeitando permissões e paginação.

Obter establishment por ID

GET /establishments/{id}

Descrição:
Retorna os dados de um establishment específico.

Criar establishment

POST /establishments

Body:
{
"name": "Empresa Exemplo",
"description": "Descrição do estabelecimento",
"document": "00.000.000/0001-00",
"email": "contato@empresa.com
"
}

Descrição:
Cria um novo establishment e o torna disponível para vinculação de usuários.

Atualizar establishment

PUT /establishments/{id}

Body:
{
"name": "Empresa Atualizada",
"description": "Nova descrição"
}

Descrição:
Atualiza os dados do establishment.

Excluir establishment

DELETE /establishments/{id}

Descrição:
Remove ou desativa um establishment, conforme regra de negócio definida.

Relação com Users (Employer)

A relação entre usuários e establishments é feita através da entidade Employer.

Um User pode pertencer a vários Establishments

Um Establishment pode ter vários Users

Employer define o vínculo e o papel do usuário no establishment

Exemplo:
User → Employer → Establishment

Relação com Orders

Um Establishment pode possuir vários Orders

Orders representam vendas, pedidos ou fluxos comerciais do establishment

Todo Order deve estar vinculado a um Establishment

Relação com Events

Um Establishment pode promover vários Events

Events são utilizados para venda de ingressos ou divulgação

Cada Event pertence a exatamente um Establishment

Permissões comuns relacionadas a Establishment

Exemplos de permissões utilizadas neste domínio:

establishments.read

establishments.create

establishments.update

establishments.delete

Usuários sem a permissão necessária receberão:
403 Forbidden

Validações comuns

Nome é obrigatório

Documento deve ser único (quando aplicável)

Email deve ser válido

Apenas usuários autorizados podem criar ou alterar establishments

Erros de validação retornam status 422 Validation Error.

Regras de negócio importantes

Um establishment pode ser desativado em vez de excluído

Estabelecimentos inativos não devem aceitar novos pedidos ou eventos

A visibilidade de establishments depende da relação via Employer

Boas práticas

Centralizar regras de negócio no domínio Establishment

Evitar duplicação de dados entre Establishment e User

Utilizar Employer para qualquer vínculo usuário ↔ establishment

Validar permissões em todas as operações sensíveis

Erros comuns

Establishment não encontrado:
{
"message": "Establishment not found"
}
Status: 404 Not Found

Sem permissão:
{
"message": "This action is unauthorized."
}
Status: 403 Forbidden

Documentos relacionados

users.md – Usuários

employers.md – Relação usuário ↔ establishment

orders.md – Pedidos

events.md – Eventos

permissions.md – Permissões

errors.md – Padrão de erros

README.md – Visão geral da API