# Nexus AMS

Nexus AMS is an open-source alliance management system for [Politics & War](https://politicsandwar.com/). It combines alliance banking, war tooling, membership operations, audit logging, and admin workflows into a single Laravel application.

The project is under active development. Expect ongoing changes, incomplete areas, and occasional breaking updates while the platform continues to mature.

## What It Does

Nexus AMS is built around day-to-day alliance operations rather than a single feature area.

- Alliance banking with member accounts, transfers, withdrawals, deposits, freezes, limits, and finance reporting
- Tax operations with brackets, direct deposit enrollment, collection jobs, and alliance-level summaries
- Grants, city grants, loans, war aid, rebuilding requests, and other approval-based finance workflows
- War tooling including counters, war plans, raid finder, war stats, war simulators, beige alerts, and intel reports
- Offshore-aware alliance management so the primary alliance and approved offshores can operate as one umbrella
- Recruitment and applications, including Discord-connected application workflows
- Payroll, MMR planning, market tools, and alliance member management
- Audit logs, audit rules, and security-sensitive event tracking
- Custom pages, content publishing, and NEL-based admin tooling/documentation
- API endpoints for user automation, Discord integrations, and the Nexus subscription pipeline

## Current Stack

- PHP 8.4
- Laravel 12
- MySQL as the default and primary supported database path
- Blade views for the UI
- Tailwind CSS + DaisyUI 4 for the user-facing side
- Bootstrap/AdminLTE for the admin side
- Vite for frontend bundling
- Sanctum, Fortify, Pulse, Telescope, and Laravel Boost
- Optional companion services for Discord and GraphQL subscription ingestion

## Related Repositories

- [Nexus AMS Subs](https://github.com/Yosodog/Nexus-AMS-Subs): subscription listener for Politics & War events that forwards updates into Nexus
- [Nexus AMS Discord](https://github.com/Yosodog/Nexus-AMS-Discord): Discord bot scaffolding and Nexus-facing automation hooks
- [Nexus-Setup](https://github.com/Yosodog/Nexus-Setup): production install/provisioning script for Nexus AMS and related services

## Quick Start

For local development, use the normal Laravel flow.

```bash
git clone https://github.com/Yosodog/Nexus-AMS.git
cd Nexus-AMS
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
composer run dev
```

`composer run dev` starts the Laravel server, queue listener, log tailer, and Vite dev server together.

Minimum local requirements:

- PHP 8.4+
- Composer
- Node.js 18+
- MySQL 8+ or compatible MariaDB
- Redis is optional locally, but recommended for production queue/cache/session workloads

Before first sign-in, set the key environment values in `.env`:

- `APP_URL`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `PW_API_KEY`
- `PW_API_MUTATION_KEY`
- `PW_ALLIANCE_ID`
- `NEXUS_API_TOKEN`
- `DISCORD_BOT_KEY` if you are using Discord-connected flows

## Production Quick Start

If you want a fast production-style install, use the companion installer from `Nexus-Setup`.

Interactive install:

```bash
curl -fsSL https://raw.githubusercontent.com/Yosodog/Nexus-Setup/main/install_nexus.sh -o install_nexus.sh
chmod +x install_nexus.sh
sudo ./install_nexus.sh
```

Preview without making changes:

```bash
sudo ./install_nexus.sh --dry-run
```

Non-interactive rerun using an existing `install.env`:

```bash
sudo ./install_nexus.sh --non-interactive
```

The current installer supports these profiles:

- `full`: app, web, database, and subscription worker
- `app-web-subs-remote-db`: app and subs with the database hosted elsewhere
- `web-only`: app/web only, no local DB or subs worker
- `db-only`: database host only
- `subs-only`: subscription worker only

The installer currently provisions or configures:

- PHP-FPM, Composer, Node.js, and Nginx
- MySQL or MariaDB when the selected profile includes a local database
- Redis when enabled
- Nexus AMS and Nexus AMS Subs clones
- Laravel migrations, seeding, and frontend build
- Supervisor workers
- Cron-based Laravel scheduler
- Certbot/TLS
- Optional initial admin user creation

## Project Structure

This repo is not a generic CRUD Laravel app. The main conventions are:

- [`app/Http/Controllers`](app/Http/Controllers): web and API entry points
- [`app/Http/Requests`](app/Http/Requests): validation and request authorization
- [`app/Services`](app/Services): core business logic and orchestration
- [`app/Models`](app/Models): Eloquent models for application state
- [`app/GraphQL`](app/GraphQL): Politics & War GraphQL data objects and hydration helpers
- [`app/Nel`](app/Nel): the NEL expression engine and related helpers
- [`app/Console/Commands`](app/Console/Commands): scheduled and operational commands
- [`resources/views`](resources/views): Blade views
- [`resources/views/admin`](resources/views/admin): Bootstrap/AdminLTE admin UI
- [`resources/views/user`](resources/views/user): user-facing UI
- [`resources/js`](resources/js): Vite-compiled JavaScript entrypoints
- [`routes/web.php`](routes/web.php): browser routes
- [`routes/api.php`](routes/api.php): API routes, subs endpoints, and bot integrations
- [`database/migrations`](database/migrations): schema changes
- [`database/seeders`](database/seeders): baseline data

Operationally, the app is split into two frontend surfaces:

- User side: Tailwind + DaisyUI
- Admin side: Bootstrap + AdminLTE

## Important App Conventions

- Use service container resolution and dependency injection rather than instantiating services directly.
- Prefer Form Request classes over inline controller validation.
- Use typed properties and explicit return types.
- Prefer collection helpers unless a loop is clearer.
- Security-sensitive actions should be logged through `App\Services\AuditLogger`.
- If a workflow allows only one pending request at a time, enforce it at the database level with the repository's `pending_key` pattern instead of relying only on app validation.
- Treat the primary alliance plus enabled offshores as one umbrella through `AllianceMembershipService`; do not hard-code direct `PW_ALLIANCE_ID` checks where umbrella membership matters.
- If a change affects cache keys, scheduled jobs, or workers, call that out clearly in documentation or PR notes.
- Do not commit `.env` changes or built frontend artifacts.

## Admin Permissions

Nexus AMS uses a role-and-permission model on top of the admin gate.

- `AdminMiddleware` blocks the admin area unless `is_admin` is set on the authenticated user.
- Fine-grained capabilities are registered from [`config/permissions.php`](config/permissions.php) and enforced through Laravel gates, form requests, policies, and Blade visibility checks.
- Navigation and admin screens are permission-aware, so access is intentionally segmented instead of treating every admin as a super-admin.

Key permission groups currently include:

- User and role management: `view-users`, `edit-users`, `view-roles`, `edit-roles`
- Finance operations: `view-accounts`, `manage-accounts`, `view-financial-reports`, `view-taxes`
- Workflow approvals: `manage-city-grants`, `manage-grants`, `manage-loans`, `manage-war-aid`, `manage-rebuilding`
- Operations and planning: `manage-war-room`, `manage-raids`, `manage-spies`, `manage-mmr`, `manage-market`
- Diagnostic and restricted tooling: `view-diagnostic-info`, `manage-custom-pages`
- Exception handling for trusted staff: `bypass-self-restrictions`

Permission design expectations:

- Add the smallest permission set that solves the problem.
- Prefer separate `view-*` and `manage-*` capabilities when a feature needs read-only and write access.
- Keep especially sensitive tools behind `view-diagnostic-info` or a similarly explicit permission.
- For approval flows, combine permission checks with self-approval restrictions where appropriate instead of assuming admin status alone is sufficient.

## Security Model

Security in Nexus AMS is layered. The app already includes several concrete protections:

- Authenticated app usage is typically gated by user verification, Discord verification, and MFA enforcement before protected user/admin routes are available.
- MFA can be required for all users or specifically for admins, and trusted-device bypasses are stored as hashed tokens with user-agent binding and expiry.
- Security headers are applied centrally, including CSP, HSTS on secure non-local requests, `X-Frame-Options`, `X-Content-Type-Options`, referrer policy, and permissions policy.
- Disabled users are force-logged out by middleware instead of being allowed to continue on stale sessions.
- Request IDs are assigned and echoed back so audit entries and production debugging can correlate a request across logs.
- Security-sensitive actions and authorization denials are written to audit logs through `AuditLogger`.
- Internal bot/subscription endpoints require bearer-token middleware instead of being left open.
- Rate limiting is in place for sensitive or abuse-prone actions such as account transfers, grant requests, and war simulations.
- Single-pending workflows use the repository's `pending_key` database pattern so concurrency rules are enforced at the data layer, not just in controller validation.
- Self-approval protections exist for workflows where a staff member should not process their own request unless they explicitly have the bypass permission.

When adding or changing security-sensitive code:

- Use Form Requests or gates for authorization, not just hidden buttons in Blade.
- Keep single-pending enforcement at the database level.
- Log approvals, denials, refunds, overrides, and other sensitive actions with useful structured context.
- Do not put secrets, tokens, passwords, or raw request payloads into logs or audit context.
- Prefer session-domain, secure, `HttpOnly` cookies for anything that reduces authentication friction.
- Treat Discord, subs, queue, scheduler, and admin diagnostic paths as privileged surfaces and document any new operational risk.

## Useful Commands

Development:

```bash
composer run dev
npm run build
./vendor/bin/pint --dirty
```

Common app commands:

```bash
php artisan sync:nations
php artisan sync:alliances
php artisan sync:wars
php artisan sync:treaties
php artisan taxes:collect
php artisan trades:update
php artisan military:sign-in
php artisan loans:process-payments
php artisan payroll:run-daily
php artisan audits:run
php artisan inactivity:check
php artisan queue:work
php artisan schedule:run
```

`php artisan schedule:list` is normally useful for inspecting the scheduler, but it requires installed Composer dependencies. In this workspace snapshot, `vendor/` was not present when this README was updated.

## AI Agent Guidance

If you are using Codex, Claude, Cursor, or another coding agent on this repo, these rules matter:

1. Read [`AGENTS.md`](AGENTS.md) before making changes.
2. Prefer understanding the surrounding feature area before editing. This app has a lot of business rules hidden in services, requests, and admin flows.
3. Use Laravel Boost tools when available. They are the fastest path for application info, docs lookup, routes, logs, config, schema, and safe database reads.
4. Search Laravel ecosystem docs before making framework-level assumptions.
5. Run `./vendor/bin/pint --dirty` after touching PHP.
6. Do not add, modify, or run automated tests in this repository unless a maintainer explicitly changes that policy.
7. Do not rely on a friendly `exists()` check alone for single-pending workflows. Preserve or add the `pending_key` database guard.
8. Never commit secrets, `.env` edits, or generated frontend build output.
9. When editing UI, preserve the repo split: Tailwind/DaisyUI for user views, Bootstrap/AdminLTE for admin views.
10. When a change affects queues, cron, caches, or deployment behavior, document the operational impact.

For humans working with AI agents, the highest-leverage pattern is:

- Give the agent a narrow goal and the files or subsystem involved
- Expect it to inspect the existing conventions first
- Require it to explain operational impact, not just code changes
- Require it to say whether formatting was run and whether anything could not be verified locally

## Contributing

Contributions are welcome, but they should stay focused and match the existing conventions.

1. Fork the repository.
2. Create a topic branch.
3. Follow the quick-start instructions.
4. Keep the change scoped to one coherent area.
5. Use descriptive present-tense commit messages.
6. Note any migration, cache clear, scheduler, or queue-worker impact in the PR description.

Before opening a PR:

- Match existing Laravel and Blade conventions
- Run `./vendor/bin/pint --dirty` for PHP formatting
- Update documentation when behavior or operational setup changes

## Reporting Bugs and Requests

- Open an issue at [Nexus AMS Issues](https://github.com/Yosodog/Nexus-AMS/issues)
- Include expected behavior, actual behavior, and reproduction steps
- For operational issues, include whether the problem is on the web app, queue worker, scheduler, subscription service, or Discord integration

## License

This project is licensed under the GNU GPL v3. See [`LICENSE`](LICENSE).
