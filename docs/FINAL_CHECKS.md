# Final Release Checks

A release of this hardening branch should not be considered complete until:

- GitHub Actions passes syntax, migrations, and tests;
- application isolation tests pass;
- migrations are reviewed against the production MariaDB version;
- a database backup is available;
- Rasoio and Nexus smoke tests are completed after deployment;
- CORS origins used in production are confirmed;
- administrator bootstrap configuration is confirmed;
- queue workers and caches are restarted/rebuilt through the normal deployment process.
