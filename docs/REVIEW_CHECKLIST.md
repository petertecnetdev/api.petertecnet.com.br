# API Review Checklist

Use this checklist when adding or changing an API resource.

- Does the resource belong to an application? If yes, is `app_id` explicit and validated?
- Can an authenticated user change an ID to access another user's data?
- Can a resource from one application be attached to another application?
- Is authorization enforced in the API rather than only in the frontend?
- Are status values validated against a finite contract?
- Are uploads validated for type, size, visibility, and ownership?
- Are list endpoints paginated when their cardinality can grow?
- Are common filters backed by appropriate indexes?
- Does serialization trigger hidden N+1 queries or expensive computed attributes?
- Are transactions used around multi-write operations?
- Is the operation safe under retries/concurrency where relevant?
- Are sensitive values excluded from logs and JSON responses?
- Does the endpoint preserve the standard HTTP/error contract?
- Is there a regression test for the critical invariant?
- If a legacy contract changes, is there a compatibility/versioning plan?
