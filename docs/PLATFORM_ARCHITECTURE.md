# Peter Tecnet API — Platform Architecture

## Purpose

The API is the shared backend platform for Peter Tecnet applications. It must provide reusable capabilities without turning application-specific behavior into global data ownership.

The central rule is:

> A user account may participate in many applications, but business resources belong to the application in which they were created.

This means a company created in Rasoio is not automatically a Nexus company. If the same real-world company wants a Nexus catalog, a second `establishments` record must be created with Nexus' `app_id`.

## Core ownership model

### User

`users` is global identity. A person has one account and may access multiple applications.

Application participation is represented by `application_user`:

- `application_id`
- `user_id`
- `role`
- `status`
- `metadata`
- `joined_at`

The pivot describes participation. It does **not** make every resource owned by the user visible in every application.

### Application

`applications` identifies a product in the ecosystem. Business-domain resources should carry `app_id` whenever they can exist independently in multiple products.

### Establishment

`establishments.app_id` is immutable after creation.

An establishment's application must never be changed to "move" a company between products. Create another establishment in the target application instead.

### Item

For establishment-owned items, these values form one logical context:

- `items.app_id`
- `items.entity_name = establishment`
- `items.entity_id = establishments.id`

`items.app_id` must equal the referenced establishment's `app_id`.

### Orders

For establishment orders and appointments:

- `orders.app_id` must equal the establishment's `app_id`;
- every selected item must have the same `app_id`, `entity_name`, and `entity_id`;
- the selected employer must belong to that establishment.

These invariants are enforced at the HTTP boundary by `EnsureOrderContext` before persistence.

## Application context

New endpoints should make application context explicit whenever a resource can be ambiguous.

Preferred options, in order:

1. an `app_id` route parameter when the resource is naturally nested in an application;
2. an `app_id` validated request/query parameter for compatibility endpoints;
3. `X-App-ID` only when a future centralized context middleware adopts and validates it consistently.

Never infer application ownership only from the authenticated user.

## Public identifiers

Legacy establishment routes identify resources using globally unique slugs. For compatibility, establishment slug generation remains globally unique for now.

A future versioned API may introduce an app-scoped public identifier such as:

`/api/v2/apps/{app}/establishments/{slug}`

Only after every consumer is migrated should the database constraint be changed from global slug uniqueness to `(app_id, slug)` uniqueness.

## Authorization rules

Authorization must be enforced in the API, never only in a frontend.

Important rules:

- changing numeric IDs must never provide access to another user's resource;
- administrator access does not bypass data-integrity invariants such as cross-application item ownership;
- only an existing administrator may assign the `Administrador` profile;
- administrator bootstrap is explicit through `PETER_ADMIN_EMAIL` and the seeder; saving a User never implicitly grants administrator privileges.

## Authentication

JWT is the API authentication mechanism.

Security-sensitive codes are stored as hashes:

- email verification codes expire after 30 minutes;
- invitation codes expire after 24 hours;
- password reset codes use their independent 10-minute expiration.

Do not add plaintext verification or reset codes to logs.

## Errors

JSON API errors use a predictable envelope where possible:

```json
{
  "success": false,
  "message": "Human-readable message",
  "error": "Human-readable message",
  "code": "MACHINE_READABLE_CODE",
  "request_id": "correlation-id"
}
```

Validation errors additionally include `errors`.

Existing successful response shapes are intentionally preserved where compatibility is important.

## Observability

Every request receives an `X-Request-ID` response header. A safe incoming request ID may be preserved; otherwise the API creates a UUID.

Unexpected exceptions are logged with:

- request ID;
- HTTP method and path;
- authenticated user ID when available;
- exception class;
- exception message.

Do not log passwords, tokens, verification codes, payment secrets, or other sensitive payloads.

## Database guidelines

Every high-volume application-owned query should have an index beginning with the application or ownership columns actually used by that query.

Current hardening adds indexes for the main access patterns of:

- establishments;
- items;
- interactions;
- orders;
- files;
- service records.

New migrations should be deterministic. `Schema::hasTable()` bootstrap migrations are a legacy transition mechanism and should not become the normal migration pattern.

## Adding a new Peter Tecnet application

1. Create one record in `applications` with a stable unique slug.
2. Register users through `application_user` when they join/use the application.
3. Always send/store the new application's `app_id` on application-owned resources.
4. Never reuse another application's establishment merely because the same user or real-world company owns it.
5. Reuse generic entities (`establishments`, `items`, `files`, orders where applicable) before creating niche-specific tables.
6. Add application-specific tables only when the data has genuinely different lifecycle or invariants.
7. Add isolation tests proving resources from app A cannot be manipulated through app B.
8. Avoid hard-coded numeric application IDs in controllers, mailers, models, or policies. Resolve applications from database records/configuration.

## Compatibility policy

Do not silently break existing frontend contracts.

When an incompatible URL or response change is necessary:

1. add a versioned endpoint;
2. keep the legacy endpoint during migration;
3. migrate known consumers;
4. instrument/deprecate the old endpoint;
5. remove it only after consumers are verified.

Data-integrity and authorization vulnerabilities are exceptions: they should be fixed immediately while preserving successful response formats whenever possible.
