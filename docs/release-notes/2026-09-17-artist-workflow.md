# Artist workflow and generic application API — 2026-09-17

## Scope

This note records the artist workflow changes that are present on `main` as of 2026-09-17 and the compatibility boundaries that downstream applications must preserve. It is intentionally limited to behavior evidenced by repository code and merged commits; open pull requests are listed separately as pending work.

## Current behavior on `main`

Recent commits on `main` establish the following contract changes:

- Artist workflow routes are mounted through the generic application API context rather than a product-specific route group.
- Solo artist identity requires an authenticated user identity and event onboarding before the profile can be treated as a valid workflow participant.
- Event participation is exposed as a complete pivot, with update/reorder behavior and producer-safe management paths.
- Private professional artist data remains protected by application and authorization boundaries.
- Artist-management route ordering is significant: management routes must be registered before the dynamic artist-slug route to avoid collisions.

The canonical route family is the generic application boundary:

```text
/v1/apps/{application}/artists/...
```

Legacy product-prefixed aliases are compatibility-only and must remain isolated in the compatibility route boundary. New integrations should not introduce additional product-prefixed aliases.

## Consumer guidance

Clients integrating the artist workflow should:

1. Resolve the application context explicitly for every request.
2. Treat artist identity and event onboarding as prerequisites for solo-artist workflows.
3. Preserve the existing route order assumptions when adding new artist endpoints.
4. Avoid depending on product-specific aliases when the generic application route is available.
5. Keep private professional fields out of public payloads unless the caller is authorized for the relevant application context.

## Pending work that is not yet part of `main`

The following active pull requests were inspected during this update and are **not** described as released behavior:

- PR #474 proposes a catalog-image generation endpoint and two Cloudflare AI configuration variables.
- PR #475 removes a duplicated legacy artist alias while preserving the compatibility boundary.
- PR #476 keeps payout obligations held when an authoritative payout destination is unavailable.
- PR #478 narrows artist claim review writes to the active application scope.

Downstream teams must not implement against these pending changes until their pull requests are merged and the resulting code is re-validated.

## Release / migration notes

No database migration or consumer-facing breaking-change procedure is documented here because the inspected `main` commits describe route/context, identity, authorization, and workflow semantics rather than a versioned schema migration. If a future change removes a legacy alias, add a dated migration note with the replacement generic route and a deprecation window before merge.

## Validation performed

- Inspected the repository default branch (`main`).
- Reviewed recent commits affecting artist workflows and route mounting.
- Reviewed open PRs for overlap and intentionally kept this documentation change separate from active runtime/security/test work.
- Confirmed the existing README describes the API as multi-application and reusable; this note extends that shared contract with current artist workflow guidance.
