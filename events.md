Events

Este documento descreve o domínio Event na API PeterTecnet.

Events representam eventos promovidos por um Establishment, podendo ser utilizados em aplicações de venda de ingressos, divulgação, gestão de eventos ou plataformas multi-estabelecimento.

O domínio Event foi projetado para ser independente, reutilizável e extensível, sem acoplamento direto a pedidos ou pagamentos.

Conceito

Event representa um evento organizado por um Establishment

Um Event pode ser apenas informativo ou comercial

Um Event pode possuir vários Items, normalmente representando ingressos

Um Event pode gerar Orders, mas não depende deles para existir

Estrutura básica do Event

Campos comuns de um event:

id

name

description

start_date

end_date

location

establishment_id

active

created_at

updated_at

Os campos podem variar conforme o tipo de evento e a regra de negócio.

Endpoints
Listar events

GET /events

Descrição:
Retorna uma lista de events acessíveis ao usuário autenticado, respeitando permissões, filtros e paginação.

Obter event por ID

GET /events/{id}

Descrição:
Retorna os dados de um event específico, incluindo seus items (ingressos), quando aplicável.

Criar event

POST /events

Body:
{
"name": "Show de Rock",
"description": "Show da banda X",
"start_date": "2025-10-01 20:00:00",
"end_date": "2025-10-01 23:00:00",
"location": "Arena Central",
"establishment_id": 1
}

Descrição:
Cria um novo event associado a um establishment.

Atualizar event

PUT /events/{id}

Body:
{
"name": "Show de Rock - Atualizado",
"location": "Nova Arena"
}

Descrição:
Atualiza os dados de um event existente.

Excluir event

DELETE /events/{id}

Descrição:
Remove ou desativa um event, conforme regra de negócio definida.

Relação com Items (Ingressos)

Um Event pode possuir vários Items

Nesse contexto, Items representam ingressos

Cada Item pode ter:

preço

quantidade disponível

regras específicas de venda

O controle de estoque pode ser feito no relacionamento Event ↔ Item

Relação com Orders

Orders podem ser gerados a partir da compra de ingressos de um Event

Um Order pode conter Items associados a um Event

O Event não depende diretamente do Order para existir

Relação com Establishment

Um Event sempre pertence a um Establishment

Apenas usuários vinculados ao establishment (via Employer) podem criar ou gerenciar events

Events herdam contexto e regras do establishment

Permissões comuns relacionadas a Event

Exemplos de permissões utilizadas neste domínio:

events.read

events.create

events.update

events.delete

Usuários sem a permissão necessária receberão:
403 Forbidden

Validações comuns

Nome é obrigatório

Datas devem ser válidas

start_date deve ser anterior a end_date

Establishment deve existir

Apenas establishments ativos podem criar events

Erros de validação retornam status 422 Validation Error.

Regras de negócio importantes

Events inativos não devem aceitar novas vendas

Um Event pode ser apenas informativo ou comercial

A exclusão de um Event pode ser lógica (active = false)

Events podem ter limite de ingressos

Datas e horários devem respeitar o timezone definido pela API

Boas práticas

Manter Event desacoplado de Order

Utilizar Item para representar ingressos

Centralizar regras de venda no domínio Order

Evitar lógica financeira diretamente no Event

Validar permissões antes de qualquer alteração

Erros comuns

Event não encontrado:
{
"message": "Event not found"
}
Status: 404 Not Found

Sem permissão:
{
"message": "This action is unauthorized."
}
Status: 403 Forbidden

Documentos relacionados

items.md – Items / Ingressos

orders.md – Pedidos

establishments.md – Estabelecimentos

employers.md – Relação usuário ↔ establishment

permissions.md – Permissões

errors.md – Padrão de erros

README.md – Visão geral da API