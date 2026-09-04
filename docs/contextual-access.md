# Contextual Access Architecture

## Goal

A Peter Tecnet user has one global identity and may simultaneously hold different authorization roles and business relationships across applications, establishments and resources.

A role is never assumed to be a permanent user attribute when its meaning depends on context.

## Core model

- `users`: global identity and authentication.
- `applications`: application context.
- `establishments`: organization / establishment context.
- `memberships`: user belongs to an establishment. Membership does not itself grant a role.
- `roles`: reusable authorization role definitions.
- `permissions`: reusable capabilities.
- `role_permissions`: capabilities granted by a role.
- `role_assignments`: user + role + contextual scope.
- `parties`: person or organization that may exist before a Peter Tecnet account exists.
- `resource_relationships`: business relationship between a subject and a resource.

## Context hierarchy

`role_assignments` supports these scopes:

1. Global — no application, establishment or resource.
2. Application — `application_id`.
3. Establishment — `application_id` + `establishment_id`.
4. Resource — optional application/establishment + `resource_type` + `resource_id`.

Permissions are resolved only inside a matching context. A role in one establishment must never leak into another establishment or application.

## Role vs relationship

Authorization roles answer **what can this user do?** Examples:

- `super_admin`
- `administrator`
- `owner`
- `manager`
- `employee`
- `operator`
- `financial_manager`
- `viewer`

Business relationships answer **how is this person related to this resource?** Examples:

- `tenant`
- `landlord`
- `guarantor`
- `customer`
- `supplier`
- `beneficiary`
- `representative`

Do not create application-specific role tables or columns such as `locaio_tenants`, `rasoio_managers`, `is_barber` or `is_producer` for responsibilities that can be represented by generic contextual access or resource relationships.

## Locaio example

The same user may be:

- `tenant` of agreement `501` in Locaio;
- `landlord` of agreement `800` in Locaio;
- `manager` of establishment `20` in Rasoio;
- `employee` of establishment `48` in Nexus.

These contexts are independent.

Recommended Locaio relationship convention:

```text
relationship_type = tenant | landlord | guarantor | representative
resource_type = agreement
resource_id = <lease/agreement id>
application_id = <Locaio application id>
```

Locaio must not interpret “not landlord” as “tenant”. The explicit resource relationship is authoritative when present. Legacy contract fields remain a temporary fallback during migration.

## API

Application account context:

```http
GET /api/v1/apps/{application}/me
```

The response includes `contextual_access.roles`, `contextual_access.memberships` and `contextual_access.relationships` while preserving legacy profile/application membership fields during the migration window.

Admin Center endpoints:

```http
GET    /api/admin/ecosystem/access/catalog
GET    /api/admin/ecosystem/users/{user}/access-contexts
POST   /api/admin/ecosystem/users/{user}/role-assignments
DELETE /api/admin/ecosystem/users/{user}/role-assignments/{assignment}
POST   /api/admin/ecosystem/users/{user}/memberships
DELETE /api/admin/ecosystem/users/{user}/memberships/{membership}
POST   /api/admin/ecosystem/users/{user}/relationships
DELETE /api/admin/ecosystem/users/{user}/relationships/{relationship}
```

## Migration policy

Legacy `profiles`, `application_user.role`, `Employer.role` and user flags remain available while clients are migrated. They are compatibility sources, not the target architecture.

Run the idempotent seeders after migrating:

```bash
php artisan db:seed --class=ContextualAccessSeeder --force
php artisan db:seed --class=ContextualAccessBackfillSeeder --force
```

The backfill:

- converts global legacy administrators to `super_admin`;
- creates owner memberships/assignments from establishment ownership;
- converts employer records into establishment memberships and generic roles;
- migrates only recognized generic `application_user.role` values;
- does not invent privileges for unknown legacy application roles;
- never reactivates an existing revoked contextual assignment when rerun.

## Removal of legacy fields

Do not delete legacy fields in the same release that introduces contextual access.

Removal should happen only after:

1. every active frontend consumes contextual access;
2. authorization policies use `ContextualAccessService` or equivalent policies;
3. metrics show no legacy authorization reads for a defined stabilization period;
4. a final audit confirms no application-specific authorization dependency remains.
