# API PeterTecnet

A **API PeterTecnet** é uma API RESTful projetada para atender **múltiplos tipos de aplicações**, incluindo:

- Sistemas de gestão de estabelecimentos
- Aplicações de pedidos e vendas
- Plataformas de eventos e venda de ingressos
- Aplicações com controle avançado de usuários e permissões

A API foi construída com foco em **reutilização**, **escalabilidade** e **separação clara de domínios**, permitindo que diferentes aplicações consumam os mesmos recursos de forma segura e consistente.

---

## 📌 Características principais

- Arquitetura REST
- Autenticação via Bearer Token (JWT)
- Controle de acesso baseado em permissões (RBAC)
- Suporte a múltiplos domínios de negócio
- API multi-tenant
- Padrões consistentes de resposta e erro

---

## 🧠 Conceitos fundamentais

### Multi-aplicação
A API não é acoplada a um único produto.  
Ela pode ser utilizada por diferentes aplicações, como:

- App de agendamento ou vendas
- App de gestão interna
- App de eventos e ingressos
- Painéis administrativos

---

### Controle de permissões (RBAC)

O acesso à API é controlado por **Profiles**.

> ⚠️ Importante:  
> **Profile não representa dados do usuário**, mas sim um **papel de acesso**.

Exemplos de Profiles:
- Admin
- Manager
- Seller

Cada Profile possui um conjunto de permissões que define quais ações podem ser executadas na API.

---

## 🧱 Domínios da API

A API é organizada em domínios claros:

### 🔐 Auth & Permissions
- **User**: Usuário autenticado da API
- **Profile**: Papel de acesso com permissões

### 🏢 Business
- **Establishment**: Empresa ou organização
- **Employer**: Relação entre User e Establishment

### 🛒 Sales
- **Order**: Pedido ou venda
- **Item**: Produto, serviço ou ingresso

### 🎟️ Events
- **Event**: Evento promovido por um Establishment
- **EventItem**: Ingressos ou itens vinculados a um evento

---

## 🔐 Autenticação

A API utiliza **JWT (JSON Web Token)** para autenticação.

### Login
