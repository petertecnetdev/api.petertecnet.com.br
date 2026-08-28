# Deployment Checklist

Before merging/deploying platform changes:

- CI is green.
- Production environment has `PETER_ADMIN_EMAIL` explicitly configured if the bootstrap seeder is used.
- Extra external frontend domains, if any, are listed in `CORS_ALLOWED_ORIGINS`.
- A current database backup exists before migrations.
- `php artisan migrate --force` is executed once on the target environment.
- Queue workers are restarted after deployment when code used by queued mail/jobs changes.
- Laravel configuration/route caches are rebuilt according to the deployment process.
- Rasoio and Nexus smoke tests confirm application isolation.
- A failed API request can be correlated using `X-Request-ID` in response/logs.
- Authentication flows are smoke-tested: password login, verification, reset, invitation, and Google login if enabled.

Do not run destructive schema operations or data normalization directly in production without a backup and a reviewed migration plan.
