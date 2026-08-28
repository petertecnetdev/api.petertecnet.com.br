# Technical Audit Summary

The current API already contains useful shared-domain abstractions and previous cleanup work, but the hardening pass identified several platform risks:

- application context existed but was not enforced consistently on related resources;
- establishment and item ownership could diverge by `app_id`;
- order creation accepted establishment/items/employer IDs without one shared context invariant;
- some legacy endpoints did not accept or normalize application context;
- the global exception handler obscured HTTP semantics behind 500 responses;
- browser CORS allowed every origin;
- administrator privileges could be assigned implicitly based on a configured email during model saves;
- a non-admin with profile configuration permission could potentially assign an Administrator profile;
- verification/invitation codes had hashes but no independent expiration timestamp;
- invite completion referenced a missing mailer;
- invitation mail used hard-coded numeric application IDs;
- common multi-tenant query patterns lacked composite indexes;
- orders used both `completed` and `attended` while the database enum only accepted one of them;
- automated tests did not cover the core multi-application isolation invariant.

The `audit/platform-hardening-2026-08-28` branch addresses these issues while preserving existing successful API contracts wherever possible.

Additional legacy/niche cleanup should continue incrementally after application consumers are verified. Large controllers and heavy model analytics remain candidates for decomposition, but they should be changed behind tests rather than rewritten wholesale.
