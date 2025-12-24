
---

# 🔐 `auth.md`

```md
# Autenticação

A API utiliza autenticação via Bearer Token (JWT).

## Login
POST /auth/login

Body:
{
  "email": "user@email.com",
  "password": "secret"
}

Response:
{
  "token": "eyJhbGciOiJIUzI1NiIs..."
}
