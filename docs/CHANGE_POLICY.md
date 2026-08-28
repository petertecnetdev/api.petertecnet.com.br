# API Change Policy

## Safe additive changes

Prefer additive changes that preserve existing consumers:

- optional validated filters;
- additional response metadata;
- new endpoints;
- new indexes;
- new nullable columns;
- stricter rejection of unauthorized or cross-application operations.

## Breaking changes

Changes to route shape, required request fields, success payload structure, public identifiers, or semantics used by existing applications require a migration plan.

Prefer a versioned endpoint when the old and new contracts cannot coexist safely.

## Security and integrity fixes

A behavior that permits IDOR, privilege escalation, cross-application data contamination, credential exposure, or invalid persistence is not considered a compatibility contract. Fix it as soon as practical while preserving successful payload shapes when possible.

## Removal of legacy code

Before removing a controller, route, model, mailer, migration compatibility path, or field:

1. search known consumers;
2. verify runtime usage where observability exists;
3. migrate consumers;
4. remove only after the dependency is no longer active.

Do not preserve dead code solely because it is old, and do not delete old code solely because it looks unused without checking dependencies.
