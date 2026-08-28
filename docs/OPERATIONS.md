# Operations

## Request tracing

Use the `X-Request-ID` returned by the API to correlate frontend reports with backend logs.

## Health and diagnostics

Do not expose stack traces or raw exception messages in production JSON responses. Investigate internal failures through logs using the request ID.

## Queues

Mail and other queued work should run under a supervised queue worker in production. Restart workers after deployments that change queued classes or templates.

## Rate limiting

The default API limiter is keyed by authenticated user ID when available, otherwise by IP. Sensitive endpoints may require dedicated stricter rate limiters as the platform evolves.

## Cache

Avoid broad cache flushes. Prefer namespaced keys/tags where the configured driver supports them. Expensive computed model attributes should not be automatically appended to large list responses.

## Database

Monitor slow queries on high-cardinality tables such as interactions, items, orders, and files. New common filter patterns should be reviewed for composite indexes using real query plans.
