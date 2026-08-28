# API Testing Strategy

## Highest-priority regression tests

Tests should protect business invariants rather than only exercise controller happy paths.

### Multi-application isolation

Always test both directions:

- valid resource operations inside one application succeed;
- a resource from application A cannot be attached, mutated, or exposed through application B.

At minimum cover establishments, items, orders, files, employers, and any future shared generic entity.

### Authorization and IDOR

For every write/read-by-ID endpoint test:

- owner access;
- unrelated authenticated user access;
- collaborator access where applicable;
- administrator access;
- numeric ID tampering.

### Authentication

Cover:

- valid and invalid login;
- email verification expiration;
- password reset expiration;
- invitation expiration;
- Google token validation;
- JWT refresh/logout behavior;
- non-enumerating password reset response.

### Database integrity

CI must run the full migration set against an empty database. Production-oriented releases should additionally be tested against a sanitized schema snapshot representative of an upgraded installation.

### Error contract

Test 401, 403, 404, 422, 429, and 500 behavior. Every API error should be traceable with `X-Request-ID`.

## CI baseline

The repository CI currently performs:

1. Composer manifest validation;
2. dependency installation;
3. PHP syntax checking;
4. Laravel route boot/listing;
5. migrations on a clean SQLite database;
6. automated tests.

No pull request changing models, controllers, middleware, migrations, routes, authentication, or authorization should be merged with a failing CI run.
