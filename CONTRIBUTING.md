# Contributing to API PeterTecnet

This repository is the shared API platform for Peter Tecnet applications. New contributors should be able to install the project, run the local checks, and understand the contribution rules without relying on private conventions.

## Before you start

- Work from a fresh branch created from `main`.
- Use a descriptive branch name such as `feat/artist-workflow`, `fix/auth-refresh`, or `docs/client-contract`.
- Keep commits small and use Conventional Commit prefixes: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`, or `perf:`.
- Do not commit `.env`, tokens, private keys, production configuration, or generated secrets.

## Local setup

The repository is a Laravel 12 project and requires PHP 8.2 or newer. Install Composer dependencies first:

```bash
composer install
```

The Composer project scripts expect a local `.env` file. If `.env.example` is available in your checkout, copy it before generating the application key:

```bash
cp .env.example .env
php artisan key:generate
```

If the repository does not yet provide `.env.example`, create local values from the configuration files before starting the application. Never copy credentials from another environment.

After configuring a local database and any required services, run migrations only against the local database:

```bash
php artisan migrate
```

## Validation before opening a PR

Run the narrowest useful checks first, then the complete local suite when practical:

```bash
php artisan route:list
php artisan config:clear
php artisan test
```

For a focused change, run the affected test file or filter in addition to the full suite when feasible. If a check cannot run locally, record the exact command and the reason in the pull request.

## Pull requests

Every pull request should explain:

1. the user or developer friction being removed;
2. the files and behavior changed;
3. compatibility impact and any migration path;
4. validation commands and their results;
5. known limitations or follow-up work.

Keep documentation and configuration changes scoped to the problem. Avoid broad repository-wide rewrites when an incremental change can establish the convention safely.

## Shared-platform rules

- Prefer generic, application-scoped behavior over product-specific defaults.
- Preserve existing consumers when introducing shared helpers, contracts, or scripts.
- Do not add compatibility aliases without documenting their owner, intended lifetime, and replacement path.
- Never bypass branch protection, force-push shared branches, merge directly into `main`, or use production/VPS/SSH access as part of normal contribution work.
