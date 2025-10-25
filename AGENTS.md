# Nexus-AMS Agent Guidelines

This document applies to the entire repository. If you discover additional `AGENTS.md` files in
subdirectories, follow the most specific guidance available.

## Project Overview
- Nexus-AMS is a Laravel 12 / PHP 8.2 application. Backend domain logic lives in `app/`, database
  migrations and seeders live in `database/`, and HTTP entry points are defined under `routes/`.
- GraphQL data objects and resolvers live in `app/GraphQL`. They model the Politics & War API, so
  be mindful of the nullable typed properties and the `buildWithJSON` hydration helpers.
- Front-end assets are built with Vite. Alpine/Vue is not used; instead the app relies on plain
  JavaScript in `resources/js/app.js` and Tailwind + DaisyUI components in `resources/views`.
- Environment configuration is managed through Laravel's config files in `config/`; never commit
  `.env` changes.

## Workflow Expectations
- Review the relevant files before editing and match the surrounding conventions (e.g. nullable
  typed properties in GraphQL models, helper-based collections in services, Blade component usage).
- Keep each change focused. If you must touch multiple domains (e.g. a controller and a Blade view)
  explain why in the commit message.
- Use present-tense, descriptive commit messages ("Update dashboard caching rules" rather than
  "Updated cache").
- If a change affects cache keys, scheduled jobs, or queue workers, call that out explicitly in the
  PR description so maintainers can refresh or restart services.

## PHP / Laravel Conventions
- Stick to Laravel's service container patterns. Resolve services with dependency injection or
  `app(ServiceClass::class)`—avoid new-ing classes directly if a binding already exists.
- Use typed properties and return types. Follow the existing pattern of defaulting nullable
  properties to `null` and casting incoming API values explicitly (see `app/GraphQL/Models`).
- Prefer Laravel collection helpers over manual loops when transforming data, unless a loop is more
  readable (e.g. when short-circuiting or accumulating complex state).
- Log errors through `Log::error()`/`warning()` with structured arrays; avoid `dd()` or `dump()` in
  committed code.
- Queue/Job classes live in `app/Jobs`. Keep them idempotent—most jobs interact with external APIs
  and may be retried.
- Run Laravel Pint for PHP formatting whenever you touch backend code:
  ```bash
  ./vendor/bin/pint
  ```
  (Do **not** add Pint as a dependency; it's already listed.)

## Database and Caching
- Database migrations belong in `database/migrations/` and should be timestamped with Laravel's
  naming convention. Seed data lives in `database/seeders/`.
- Many services cache expensive queries (e.g. `TaxService` warms `tax_summary_stats`). When you
  change the underlying data shape, update the cache key names or invalidate them accordingly.
- Avoid hard-coding alliance IDs or environment-specific values—read them from config or env-aware
  helpers.

## Blade, Tailwind, and Assets
- Blade views in `resources/views` use Tailwind with DaisyUI themes. Prefer utility classes already
  present in the file and keep markup terse by leveraging Blade components/partials where available.
- JavaScript lives primarily in `resources/js/app.js` and should remain ES modules compiled by Vite.
  Import libraries at the top and avoid adding global variables unless absolutely necessary.
- After modifying front-end assets, rebuild with Vite locally (`npm run build`) if you need to
  sanity-check output, but do not commit build artifacts.

## Testing Policy
- This project intentionally avoids automated tests. **Do not add, modify, or run any automated
  tests** (including PHPUnit, Pest, Playwright, etc.). Rely on code review and manual QA notes
  instead.

## Pull Request Guidance
- Summaries must list the user-visible or operational effects of the change. Call out any manual
  steps (migrations, cache clears, queue restarts) that maintainers must run post-deploy.
- Limit PRs to a coherent set of changes; open a separate PR for unrelated cleanups.

## Documentation and Communication
- Update README sections or inline docblocks when you change behavior that developers rely on (e.g.
  service contracts, configuration keys, or scheduled commands).
- If any ambiguity remains after reading the surrounding code, leave TODO comments with clear
  follow-up instructions instead of speculative implementations.
