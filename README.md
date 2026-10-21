# Nexus AMS

Nexus AMS is an open-source alliance management system for [Politics & War](https://politicsandwar.com/). It brings banking, grants, loans, recruitment, audits, military operations, and member services into one application, with Discord commands and notifications connected to the same records and permissions.

## Get started

- **Run Nexus for your alliance:** install it with [Nexus Setup](https://github.com/Yosodog/Nexus-Setup), which sets up a production server from published releases in a few prompts. See [Production and self-hosting](#production-and-self-hosting).
- **Add Discord:** install the [Discord bot](https://github.com/Yosodog/Nexus-AMS-Discord) as an optional component after Core.
- **Contribute:** see [Local development](#local-development).

Install from a published release. The `main` branch is for ongoing development and can be ahead of the latest release.

## Why Nexus

Alliance tools often begin as a collection of Discord commands. That works for simple actions, but it becomes difficult to track applications, approvals, balances, policy decisions, military assignments, and staff access across channels.

Nexus keeps that work in a proper application. Members have one place for accounts, requests, readiness, and alliance programs. Staff have searchable queues, reports, policy controls, and an audit trail. Discord remains available for quick commands, private notifications, interviews, intelligence capture, and war rooms, but Nexus is the source of truth.

The systems share the same member records, permissions, settings, and history. A grant requested through Discord follows the same requirements as one requested on the web. A staff decision remains visible after the Discord conversation is gone. Many alliance policies can be changed from the admin interface without adding a new command or editing application code.

## Screenshots

### Member overview

The member dashboard puts open tasks, audit findings, military readiness, and stockpile warnings in one place.

![Nexus member dashboard with open audit findings and military readiness details](docs/screenshots/member-dashboard.png)

### Staff work queue

Staff get one permission-aware queue for applications, grants, loans, withdrawals, and other work that needs review.

![Nexus staff work queue with pending applications, grants, loans, and withdrawals](docs/screenshots/staff-work-queue.png)

### Custom grant programs

Grant programs combine a configurable payout with nested eligibility rules. Staff can use nation data, policies, projects, military levels, membership history, and other fields without writing code.

![Nexus custom grant editor with payout controls and nested eligibility rules](docs/screenshots/custom-grant-builder.png)

### MMR Assistant

MMR Assistant can reserve part of a member's Direct Deposit income for resources. Automatic mode estimates recurring shortages and shows the expected cost and coverage before each purchase.

![Nexus MMR Assistant showing automatic resource coverage and price estimates](docs/screenshots/mmr-assistant.png)

### Member audits and build guidance

Members can review active findings, remediation guidance, and city build recommendations based on current alliance policy.

![Nexus member audit and city build recommendation](docs/screenshots/member-audit.png)

### Loans and amortization

Loan pages show principal, scheduled payments, interest, term progress, payoff information, and the full amortization schedule.

![Nexus loan details and amortization schedule](docs/screenshots/loan-amortization.png)

### War operations

The operations workspace combines incoming wars, counter requests, raid-policy violations, delivery status, and staff actions.

![Nexus war operations queue with counter requests and raid-policy violations](docs/screenshots/war-operations.png)

### Counter coordination

Counter coordinators can inspect an incoming war, compare recommended defenders, and confirm that the team is ready before creating a war room.

![Nexus counter coordination workspace with a recommended defensive team](docs/screenshots/counter-coordination.png)

### Audit rule creation

Staff can build audit rules with grouped conditions, exceptions, priorities, scheduled evaluation, member-facing explanations, and remediation guidance. A plain-language summary shows what the rule will do before it is activated.

![Nexus audit rule builder with conditions, exceptions, and member guidance](docs/screenshots/audit-rule-builder.png)

## Features

### Banking and alliance finance

- Member and alliance accounts with deposits, withdrawals, transfers, limits, freezes, and downloadable statements
- Staff review queues, liquidity tools, reconciliation, finance reports, and a durable history of decisions
- Tax brackets, collection jobs, import checkpoints, alliance summaries, and offshore support
- Payroll, an alliance market, Growth Circles, and a configurable weekly lottery
- Direct Deposit, which applies a city-based tax bracket and credits the remaining revenue to the member's chosen account
- MMR Assistant, which can use Direct Deposit funds to cover projected resource shortages or follow a member's manual allocation

### Grants, loans, and member support

- City grants, custom grant programs, loans, war aid, rebuilding assistance, and manual disbursements
- One-time or repeatable programs with configurable money and resource payouts
- Nested eligibility rules based on nation, city, economy, military, policy, project, membership, and stockpile data
- Clear failure messages so members can see which requirements they have not met
- Server-side checks shared by the web application and Discord bot
- Approval controls, request history, amortization schedules, and protection against duplicate pending requests

Grant requirements are not tied to a fixed command. Staff can require a minimum city count, a specific project, a military threshold, time since the last project, a resource level, or a nested combination of conditions. Rules can require all conditions, any condition, or none of them. Staff can revise the policy from the admin interface, and Nexus applies it everywhere the program is available.

### Recruitment and membership

- Scheduled recruitment messages for eligible nations, with contact history to prevent duplicate outreach
- An optional follow-up message after 60 hours, skipped when the nation has already joined an alliance
- Public recruitment pages, application forms, and account verification
- Discord interview channels with recoverable Nexus metadata and transcript forwarding
- Staff review, onboarding, member history, inactivity workflows, and alliance membership checks

### Military operations and intelligence

- Incoming-war monitoring, counter recommendations, assignments, war plans, and readiness checks
- Raid finding, raid-policy violations, war statistics, attack simulators, beige alerts, and reimbursement workflows
- Spy operations, intelligence reports, and member or API report submission
- Automatic capture of pasted in-game intelligence reports through the Discord bot
- Discord war-room creation and archival with the plan and delivery state retained in Nexus
- Staff workspaces for reviewing targets, recommended defenders, available slots, and team coverage

The raid finder ranks targets by each member's expected net return, with suggested attack approaches, costs, confidence, and live availability checks. Raids are recorded automatically, including wars declared outside the finder. **My raid results** compares each prediction with the actual outcome, and admins can review rolling 30-day accuracy under **Prediction assessment** in raid settings.

### Audits and readiness

- Configurable audit rules with priorities, grouped conditions, exceptions, and scheduled evaluation
- Member-safe findings with explanations, remediation guidance, and decision history
- Military readiness and resource targets by city tier
- City build recommendations evaluated against a member's current cities and alliance policy
- Permission-scoped staff views and audit logging for sensitive actions

### Federation

Federation is an advanced, opt-in feature for sharing selected military planning data between Nexus installations. It is disabled by default.

Each link is bilateral and uses signing and encryption keys. Administrators control sharing by direction, peer, coalition, and resource type. The first federation protocol supports war-plan snapshots, including recipient review, expiry, payload hashes, signed delivery, and local draft import. Each installation keeps its own authoritative records and can suspend or revoke links and keys.

See [Federation operations](docs/federation-operations.md) and the [federation protocol](docs/federation-protocol-v1.md) for the full model.

### Member tools and customization

- Profitability and raid leaderboards with date filters and member rankings
- City, infrastructure, land, project, research, military purchase, and city-economics calculators
- Custom pages and Nexus Expression Language support for alliance-specific content
- Configurable public content, roles, permissions, notifications, tax behavior, offshores, military settings, and operational rules
- Personal API tokens and protected endpoints for additional automation

## Companion services

Nexus is split across four repositories: this web application (Core), the Discord bot, the subscription listener (Subs), and the installer. Each is versioned and released on its own. Nexus Setup installs the newest releases that are compatible with each other, so you do not need to match version numbers yourself.

### Nexus AMS Discord

[Nexus AMS Discord](https://github.com/Yosodog/Nexus-AMS-Discord) connects Discord to Nexus permissions and workflows. It supports commands for accounts, transactions, requests, grants, loans, builds, war aid, raids, wars, spy assignments, and applications.

The bot also handles account linking, private notifications, interview channels, intelligence-report capture, member departures, alliance role removal, and war-room creation and archival. Nexus authorizes every action, so Discord roles never grant Nexus permissions.

Each bot connects one Discord server to one Nexus installation. On a Nexus Setup host, install it with `sudo nexus component install discord` or from **Admin → Settings → Software**. A shared, multi-alliance hosted bot exists, but public onboarding for it is not available yet.

### Nexus AMS Subs

[Nexus AMS Subs](https://github.com/Yosodog/Nexus-AMS-Subs) listens for Politics & War GraphQL subscription events through Pusher. It sends nation, alliance, and war changes to Nexus without waiting for the next scheduled full synchronization.

Nexus Setup installs Subs alongside Core by default, and it can also run on a separate worker host.

### Nexus Setup

[Nexus Setup](https://github.com/Yosodog/Nexus-Setup) installs and updates a production deployment on Ubuntu and Debian amd64 hosts. It sets up packages, Nginx, the database, systemd services, TLS, migrations, and the first administrator, and it can split Core, Subs, and the database across hosts.

## Stack

- PHP 8.3 and Laravel 13
- MySQL or compatible MariaDB
- Blade and Livewire 3
- Tailwind CSS 4, DaisyUI 5, and MaryUI 2
- Vite 6
- Sanctum, Fortify, Pulse, and Telescope

## Local development

Requirements:

- PHP 8.3 or later
- Composer
- Node.js 20 or later
- MySQL 8 or compatible MariaDB
- Redis is optional locally and recommended for production queue, cache, and session workloads

```bash
git clone https://github.com/Yosodog/Nexus-AMS.git
cd Nexus-AMS
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Edit `.env` before migrating: set `APP_URL`, the `DB_*` database credentials, `PW_API_KEY`, `PW_API_MUTATION_KEY`, `PW_ALLIANCE_ID`, and `NEXUS_API_TOKEN`. Set `DISCORD_BOT_KEY` only if you are connecting a Discord bot. Do not commit `.env`.

```bash
php artisan migrate --seed
npm run build
composer run dev
```

`composer run dev` starts the Laravel server, queue listener, log tailer, and Vite development server.

Useful checks before submitting a change:

```bash
./vendor/bin/pint --dirty
composer test:fast
npm run build
```

The hosted-runtime MySQL closure matrix requires Docker and runs its own digest-pinned,
loopback-only MySQL fixture with generated runtime credentials:

```bash
composer test:hosted-mysql
```

See [AGENTS.md](AGENTS.md) for repository conventions, testing guidance, and security requirements. UI changes should follow [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md).

## Production and self-hosting

Install Nexus with [Nexus Setup](https://github.com/Yosodog/Nexus-Setup). You need an Ubuntu or Debian amd64 server, a domain pointed at it with ports 80 and 443 open, the administrator's email, password, and nation ID, your alliance ID, and a Politics & War API key and mutation key.

```bash
curl -fsSL https://raw.githubusercontent.com/Yosodog/Nexus-Setup/main/bootstrap.sh | sudo bash
sudo nexus install
```

The [installation guide](https://github.com/Yosodog/Nexus-Setup/blob/main/docs/getting-started.md) covers every prompt, and [installation profiles](https://github.com/Yosodog/Nexus-Setup/blob/main/docs/installation-profiles.md) cover remote and split databases.

After installation, update with `sudo nexus update`, or from **Admin → Settings → Software** if your account has the `manage-system` permission. Updates run migrations and keep the previous release for code rollback, but they **do not back up the database**. Take your own backups. Read the [operations guide](https://github.com/Yosodog/Nexus-Setup/blob/main/docs/operations.md) before your first update.

Use `NEXUS_RUNTIME=standalone` (the default) for self-hosted installations. The repository's container build and hosted runtime modes are for the managed Nexus Cloud environment, which is not open to the public yet.

## Contributing

Keep changes focused and follow the conventions in [AGENTS.md](AGENTS.md). Use descriptive, present-tense commit messages. Pull requests should describe user-visible effects and call out migrations, cache clears, scheduler changes, or queue-worker restarts.

Before opening a pull request, run the relevant tests and `./vendor/bin/pint --dirty` after changing PHP. Do not commit secrets, `.env` files, or built frontend assets.

## Reporting bugs and requests

Open an issue in [Nexus AMS Issues](https://github.com/Yosodog/Nexus-AMS/issues). Include the expected behavior, actual behavior, and reproduction steps. For operational issues, note whether the problem affects the web application, queue worker, scheduler, subscription service, or Discord integration.

## License

Nexus AMS is licensed under the GNU GPL v3. See [LICENSE](LICENSE).
