Items

Este documento descreve o domínio Item na API PeterTecnet.

Items representam entidades comercializáveis dentro da plataforma.
Um Item pode ser um produto, serviço ou ingresso, dependendo do contexto de uso.

Conceito

Item é uma entidade genérica de venda

Pode ser utilizado em diferentes domínios

Um Item pode:

Fazer parte de um Order

Ser vinculado a um Event (ingresso)

A lógica de uso do Item depende do contexto (Order ou Event)

Tipos de Item

Um Item pode representar:

Produto físico

Serviço

Ingresso para evento

Qualquer entidade vendável

A diferenciação pode ser feita por campos como tipo, categoria ou relacionamento.

Estrutura básica do Item

Campos comuns de um item:

id

name

description

price

type

active

establishment_id

created_at

updated_at

Os campos podem variar conforme a regra de negócio.

Endpoints
Listar items

GET /items

Descrição:
Retorna uma lista de items disponíveis, respeitando permissões e filtros.

Obter item por ID

GET /items/{id}

Descrição:
Retorna os dados de um item específico.

Criar item

POST /items

Body:
{
"name": "Ingresso VIP",
"description": "Ingresso para área VIP",
"price": 150.00,
"type": "event",
"establishment_id": 1
}

Descrição:
Cria um novo item que poderá ser utilizado em pedidos ou eventos.

Atualizar item

PUT /items/{id}

Body:
{
"name": "Ingresso Premium",
"price": 180.00
}

Descrição:
Atualiza os dados de um item existente.

Excluir item

DELETE /items/{id}

Descrição:
Remove ou desativa um item, conforme regra de negócio.

Relação com Orders

Um Order pode conter vários Items

Items representam o que está sendo vendido no pedido

O mesmo Item pode ser reutilizado em diferentes Orders

Relação com Events

Um Event pode possuir vários Items

Nesse contexto, Items representam ingressos

A quantidade e disponibilidade podem ser controladas por regra de negócio

Permissões comuns relacionadas a Item

Exemplos de permissões utilizadas neste domínio:

items.read

items.create

items.update

items.delete

Usuários sem a permissão necessária receberão:
403 Forbidden

Validações comuns

Nome é obrigatório

Preço deve ser maior ou igual a zero

Establishment deve existir

Item não pode ser criado para establishment inexistente

Erros de validação retornam status 422 Validation Error.

Regras de negócio importantes

Items inativos não devem ser vendidos

Preço pode variar conforme o tipo do item

Items vinculados a Events podem ter regras adicionais de estoque

Um Item pode ser compartilhado entre múltiplos Orders

Boas práticas

Manter Item como entidade genérica

Não duplicar Item para cada Order

Utilizar relacionamento para controle de contexto

Centralizar regras de venda no domínio Order ou Event

Erros comuns

Item não encontrado:
{
"message": "Item not found"
}
Status: 404 Not Found

Sem permissão:
{
"message": "This action is unauthorized."
}
Status: 403 Forbidden

Documentos relacionados

orders.md – Pedidos

events.md – Eventos

establishments.md – Estabelecimentos

permissions.md – Permissões

errors.md – Padrão de erros

README.md – Visão geral da API