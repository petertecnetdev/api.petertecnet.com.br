Permissions

Este documento descreve o sistema de permissões e Profiles da API PeterTecnet.

A API utiliza um modelo de controle de acesso baseado em papéis (RBAC – Role Based Access Control), onde as permissões são atribuídas a Profiles, e os Profiles são associados aos Users.

Conceitos principais
User

Representa o usuário autenticado na API

Não possui permissões diretamente

Herda permissões através de um Profile

Profile

Representa um papel de acesso

Define quais ações um usuário pode executar

Pode ser reutilizado por vários usuários

Centraliza o controle de permissões

Exemplos de Profiles:

Admin

Manager

Seller

Viewer

Permission

Representa uma ação específica na API

É utilizada para proteger rotas e recursos

É atribuída a um Profile

Exemplo de permissões:

users.read

establishments.create

orders.update

events.delete

Modelo de relacionamento

User
→ possui um Profile
→ Profile possui várias Permissions

As permissões não são atribuídas diretamente ao User.

Estrutura básica do Profile

Campos comuns de um profile:

id

name

description

created_at

updated_at

As permissões geralmente são armazenadas em uma tabela relacionada ou estrutura equivalente.

Estrutura básica da Permission

Campos comuns de uma permission:

id

name

description

created_at

updated_at

O campo name deve ser único e padronizado.

Padrão de nomenclatura de permissões

As permissões seguem o padrão:

dominio.acao

Exemplos:

users.read

users.create

users.update

users.delete

establishments.read

orders.create

orders.update

events.create

Esse padrão facilita:

Manutenção

Leitura

Escalabilidade

Uso das permissões na API

As permissões são utilizadas para proteger rotas.

Exemplo conceitual:

Rota: POST /events

Permissão exigida: events.create

Se o usuário não possuir a permissão necessária, a API retorna:
403 Forbidden

Profiles padrão (exemplo)

Exemplo de profiles e permissões associadas:

Admin

Acesso total a todos os domínios

Manager

establishments.read

establishments.update

orders.read

orders.update

events.read

events.create

Seller

orders.read

orders.create

items.read

Viewer

users.read

establishments.read

events.read

Esses exemplos podem ser ajustados conforme a regra de negócio.

Contexto de Establishment

Embora as permissões sejam globais via Profile, o acesso aos dados também depende do contexto de Establishment.

Regras importantes:

O usuário precisa ter um Employer ativo

O Profile define o que pode fazer

O Employer define onde pode fazer

Exemplo:
Um usuário pode ter permissão orders.create, mas só poderá criar orders para establishments aos quais está vinculado.

Validações de permissão

Antes de executar uma ação, a API deve validar:

O usuário está autenticado

O usuário possui a permissão exigida

O usuário possui vínculo válido com o establishment (quando aplicável)

Falha em qualquer etapa resulta em erro.

Erros comuns relacionados a permissões

Sem permissão:
{
"message": "This action is unauthorized."
}
Status: 403 Forbidden

Usuário não autenticado:
{
"message": "Unauthenticated."
}
Status: 401 Unauthorized

Boas práticas

Nunca atribuir permissões diretamente ao User

Centralizar permissões em Profiles

Utilizar nomenclatura consistente

Revisar permissões antes de expor novas rotas

Evitar Profiles com responsabilidades confusas

Documentar bem o papel de cada Profile

Evolução futura

O sistema de permissões pode evoluir para:

Permissões por contexto

Permissões temporárias

Auditoria de ações

Políticas mais avançadas (ABAC)

Documentos relacionados

auth.md – Autenticação

users.md – Usuários

employers.md – Vínculo usuário ↔ establishment

errors.md – Padrão de erros

README.md – Visão geral da API