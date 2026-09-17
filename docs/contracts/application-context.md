# Application Context Contract

## Purpose

The API is a shared platform for multiple Peter Tecnet applications. Every reusable domain capability must resolve and enforce the active application context before reading or mutating application-owned data.

## Canonical boundary

Canonical application-scoped routes use the following shape:

```text
/api/v1/apps/{application}/...
```

The route layer must resolve `{application}` to the authoritative application record and expose that context to downstream services, policies, jobs, events and notifications.

## Mandatory invariants

1. Every application-owned query must include the active application boundary.
2. Every application-owned write must validate the active application boundary before persistence.
3. Ownership checks must combine resource identity with the active application context.
4. Transactions must capture the authoritative application id before entering closures or queued work.
5. Queued jobs, events and notifications must carry application identity explicitly; they must not infer it from mutable global state.
6. Cross-application reads and writes are forbidden unless the capability explicitly defines a platform-level contract.
7. Legacy product-prefixed aliases may remain only as compatibility adapters that delegate to the canonical application-scoped implementation.

## Reuse rule

A capability is platform-ready when another application can consume the same service, policy, query or contract by providing its application context, without copying product-specific business rules.

When duplication is found, follow:

```text
IDENTIFY → GENERALIZE → IMPLEMENT → PRESERVE COMPATIBILITY → TEST → PR
```

## Review checklist

Before opening a pull request that touches a shared capability, verify:

- the active application id is authoritative and immutable for the operation;
- all repository queries are scoped by application where required;
- related models loaded through relationships cannot escape the application boundary;
- bulk updates and deletes use the same application predicate;
- queued work serializes the application identity;
- regression coverage includes two applications with same-named or same-keyed resources;
- legacy routes preserve behavior by delegating to the canonical contract.

## Compatibility

This contract is additive. It documents the existing platform boundary and does not change HTTP payloads, route names, database schema or legacy aliases by itself.
