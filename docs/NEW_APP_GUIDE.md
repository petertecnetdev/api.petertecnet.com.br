# New Application Integration Guide

When adding a new Peter Tecnet product:

1. Add a row to `applications`; do not encode the numeric ID in source code.
2. Use the database application ID in every application-owned resource request.
3. Register account participation through `application_user`.
4. Create establishments specifically for this application, even when the same real-world company already exists in another product.
5. Attach items only to establishments from the same application.
6. Keep generic business rules in the API; frontend code is responsible for presentation, not enforcement.
7. Reuse the shared file subsystem and ownership checks.
8. Add feature tests for cross-application rejection before release.
9. Use the standard error contract and propagate `X-Request-ID` in frontend diagnostics.
10. Add niche-specific data structures only when generic establishments/items cannot represent the lifecycle correctly.
