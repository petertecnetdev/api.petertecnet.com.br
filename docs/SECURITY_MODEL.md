# API Security Model

## Trust boundary

The frontend is not a security boundary. IDs, application context, permissions, ownership, prices, status transitions, uploads, and other critical input must be validated again by the API.

## Identity and application participation

`users` is global identity. `application_user` records participation in an application.

Participation does not grant ownership of another application's resources.

## Resource isolation

For application-owned resources, authorization and data integrity are separate concerns.

An administrator may have authorization to manage a resource but still cannot violate an integrity invariant. For example, an administrator cannot create a Nexus item that points to a Rasoio establishment.

## Establishments

An establishment belongs to one application for its entire lifecycle. `app_id` is immutable.

Write access is granted to the owner/creator or an administrator, subject to data-integrity rules.

## Items

When `entity_name=establishment`, an item must have:

- the same `app_id` as its establishment;
- the referenced establishment ID as `entity_id`;
- a valid authenticated manager for write operations.

## Orders and appointments

Before order creation:

- order application must match establishment application;
- employer must belong to the establishment;
- every item must belong to the establishment and application;
- item must be active.

Existing order read/update routes additionally use `EnsureOrderAccess` to protect against IDOR.

## Administrator privileges

The `Administrador` profile is privileged.

Rules:

- only an administrator may assign the Administrator profile;
- saving a user never auto-promotes based on email;
- bootstrap through `AdminUserSeeder` requires an explicit `PETER_ADMIN_EMAIL` environment value;
- no administrator email is hard-coded in repository behavior.

## Authentication codes

Verification, invitation, and password reset codes are stored hashed.

They must also expire. A valid hash without a valid expiration is rejected.

## CORS

CORS restricts browser origins. It is not authorization.

Default trusted browser origins are Peter Tecnet HTTPS subdomains and localhost development origins. Extra origins must be explicitly configured.

## Logging

Unexpected server exceptions include a request correlation ID in logs. Sensitive values must never be logged, including:

- passwords;
- JWTs;
- verification/reset codes;
- payment credentials/secrets;
- private uploaded file content.
