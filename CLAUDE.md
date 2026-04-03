# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Nexus AMS is a Laravel 12 / PHP 8.4+ alliance management system for the game **Politics & War**. It handles banking, taxes, loans, grants, war management, recruitment, and Discord integration for in-game alliances.

## Development Commands

```bash
# Start all dev services (server, queue, logs, vite)
composer run dev

# Run migrations
php artisan migrate

# Build frontend assets
npm run build

# Format PHP code (run after any backend change)
./vendor/bin/pint

# Run a single test
php artisan test --filter=testName

# Run all tests in a file
php artisan test tests/Feature/ExampleTest.php

# Run full test suite
php artisan test
```

> **No automated tests policy**: This project intentionally avoids automated tests. Do not add, modify, or run any automated tests. Rely on code review and manual QA.

## Architecture

### Key Directory Layout

- `app/GraphQL/Models/` — PHP objects that mirror the Politics & War API response shapes. All properties are nullable and hydrated via `buildWithJSON()`. Do not instantiate raw — match the existing nullable typed property pattern.
- `app/Services/` — Domain logic. Services are resolved via DI or `app(ServiceClass::class)`. Never `new` a service directly.
- `app/Jobs/` — Queue jobs. All jobs should be idempotent (they may be retried on failure).
- `app/Console/Commands/` — Artisan commands. All commands auto-register; no manual registration needed.
- `routes/console.php` — Laravel Scheduler definitions. All scheduled jobs live here.
- `resources/views/` — Blade templates. User-facing views use Tailwind + DaisyUI 4. Admin views use Bootstrap + AdminLTE.
- `resources/js/app.js` — Plain ES modules compiled by Vite. No Alpine or Vue.

### Two Frontend Themes

The app has two distinct UI sections:
- **User side**: Tailwind CSS v3 + DaisyUI 4 components
- **Admin side**: Bootstrap 5 + AdminLTE

When editing views, match the styling approach of the section you're working in.

### Alliance Membership

Always use `AllianceMembershipService` to check if a nation belongs to the alliance group — never read `PW_ALLIANCE_ID` directly. The service merges the primary alliance ID with enabled offshore alliance IDs and caches the result.

```php
$membership = app(\App\Services\AllianceMembershipService::class);
if ($membership->contains($nation->alliance_id)) { ... }
```

### Audit Logging

Use `App\Services\AuditLogger` for security-sensitive actions. Use `recordAfterCommit()` for DB-transaction-wrapped changes.

### Pending-Key Pattern (important for data integrity)

For workflows that allow only one pending request at a time, enforce the constraint at the database level using the `pending_key` pattern:
- Set `pending_key = 1` while pending; set to `null` when approved/denied/cancelled
- Add a unique index on `(nation_id, pending_key)` (or equivalent)
- Catch unique constraint violations in the service and convert to user-facing validation errors
- Recovery tools for stuck pending rows go on the admin settings page behind `view-diagnostic-info` permission

### Permissions

All permissions are defined in `config/permissions.php`. Admin access is gated by `AdminMiddleware`. Use the `view-diagnostic-info` permission for admin diagnostic/recovery tools.

### Scheduled Jobs

All scheduled commands are in `routes/console.php`. Most are gated on `PWHealthService::isUp()` to skip when the P&W API is down. Use `withoutOverlapping()` on any job that might run long.

### API Routes

- `routes/api.php` — REST API consumed by the Discord bot and Nexus-AMS-Subs service
- Bot API routes use `ValidateDiscordBotAPI` middleware
- Nexus subscription listener uses `ValidateNexusAPI` middleware

## PHP / Laravel Conventions

- Use constructor property promotion: `public function __construct(public FooService $foo) {}`
- Always use explicit return types and typed parameters
- Always use curly braces for control structures
- Prefer `Model::query()` over `DB::` for queries
- Use Form Request classes for validation — never inline in controllers
- Use named routes and `route()` for URL generation
- Use `config('key')` — never `env()` outside of config files
- Log via `Log::error()`/`warning()` with structured arrays; no `dd()` or `dump()` in committed code
- Run `./vendor/bin/pint` after any PHP changes

## Database

- Migrations: `database/migrations/` with Laravel timestamp naming convention
- When modifying a column in a migration, include **all** previously defined attributes or they will be dropped
- Many services cache expensive queries — update cache keys when changing data shapes

## Frontend

- After modifying frontend assets, run `npm run build` to check output; do not commit build artifacts
- JavaScript stays in `resources/js/app.js` as ES modules
- CKEditor content rendered with `{!! !!}` must be server-side sanitized first

## Security Notes

- Trusted-device / MFA bypass cookies: use the session domain and mark `secure` whenever the current request is HTTPS
- For single-pending-at-a-time workflows, always use the `pending_key` DB pattern (see above) — app-only `exists()` checks are insufficient

## Commit Style

- Present-tense, descriptive messages: `"Update dashboard caching rules"` not `"Updated cache"`
- If a change affects cache keys, scheduled jobs, or queue workers, call that out in the commit/PR description
