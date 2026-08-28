# API Hardening — 2026-08-28

## Scope

This change set completes a focused platform-hardening pass over the current `main` branch without replacing healthy existing contracts.

## Implemented

- strict application consistency between establishments and items;
- immutable `establishments.app_id`;
- order creation context validation for application, establishment, employer, and items;
- explicit application participation through `application_user` when an establishment is created;
- app-aware legacy listings where compatibility permits an optional `app_id`;
- request correlation IDs through `X-Request-ID`;
- normalized JSON exception responses for 401/403/404/422/429/500;
- explicit administrator bootstrap instead of implicit email-based privilege elevation;
- protection against assigning the Administrator profile from non-admin accounts;
- expiration for email verification and invitation codes;
- completed invite confirmation mailer that was previously referenced but missing;
- removal of hard-coded application numeric IDs from invitation mail;
- restricted CORS policy for Peter Tecnet domains and local development;
- database indexes for high-frequency application/ownership queries;
- compatibility for legacy `completed` and `attended` appointment status values;
- feature tests covering the core Rasoio/Nexus isolation invariant;
- architecture documentation for future applications.

## Deployment requirements

Before deployment, configure environment values intentionally:

```env
PETER_ADMIN_EMAIL=<administrative-account-email>
CORS_ALLOWED_ORIGINS=
```

`CORS_ALLOWED_ORIGINS` is optional and should contain comma-separated extra browser origins only when an application is hosted outside `*.petertecnet.com.br`.

The configured admin user must already exist before `AdminUserSeeder` can grant the Administrator profile. Saving/registering a user no longer grants privileges based on email.

## Database migration

Run:

```bash
php artisan migrate --force
```

The hardening migration:

- adds `users.verification_code_expires_at`;
- ensures `establishments.created_by` exists;
- creates missing operational indexes;
- expands the MySQL/MariaDB appointment status enum to accept both legacy `completed` and `attended` values.

## Compatibility notes

Establishment slugs remain globally unique because existing public routes identify an establishment by slug without an application in the route. Do not change the unique database constraint to `(app_id, slug)` until consumers move to a versioned app-scoped public URL.

Successful response shapes have intentionally been preserved. Error responses now provide additional standard fields but retain a human-readable `error`/`message` for frontend compatibility.

## Required verification after deployment

- Rasoio home only shows Rasoio establishments/items;
- Nexus account only lists Nexus establishments when using its `app_id`;
- creating a Nexus item against a Rasoio establishment returns 422;
- trying to change an establishment's `app_id` returns 409;
- direct orders and appointments reject items/employers from another establishment/application;
- login, verification, password reset, invitation, and Google login still complete successfully;
- API responses contain `X-Request-ID`;
- frontend origins outside Peter Tecnet domains are blocked unless explicitly configured.
