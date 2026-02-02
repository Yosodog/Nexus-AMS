# Nexus AMS

## About Nexus AMS
Nexus AMS (Alliance Management System) is an open-source web application for managing alliances in the game **Politics & War**. It provides tools for alliance banking, war management, resource tracking, taxation, loans, recruitment, and more. It is intended to be a successor to BK Net and reach feature parity.

## Alpha Warning
This project is currently in **Alpha** and is **not** production-ready. Expect breaking changes, incomplete features, and potential security risks. Use at your own risk!

## Why PHP and Laravel?
Nexus AMS is built using **PHP 8.4+** and **Laravel 12**, leveraging the framework's robustness, scalability, and ease of development.

## Companion services and tooling
Nexus AMS ships alongside a few related projects:
- [Nexus AMS Subs](https://github.com/Yosodog/Nexus-AMS-Subs): Node.js listener that consumes Politics & War GraphQL subscriptions and forwards live events (nations, alliances, wars) to the Nexus API instead of polling.
- [Nexus AMS Discord Bot](https://github.com/Yosodog/Nexus-AMS-Discord): Discord bot scaffolding with slash-command support and a Nexus API client stub to extend coordination and verification workflows.
- [Nexus-Setup](https://github.com/Yosodog/Nexus-Setup): Shell-based installer for provisioning a production-ready Nexus AMS + Subs stack on Ubuntu (Nginx, PHP, MySQL, Supervisor, and TLS) with one command.

## How Nexus AMS Works
Nexus AMS is structured to be modular and scalable:

### Key Features (In current state):
- **Alliance banking and finance:** Track deposits, withdrawals, and a unified finance ledger.
- **Taxes and direct deposit:** Collect taxes, model brackets, and report intake across money and resources.
- **Grants and loans:** Manage grant programs, applications, approvals, and scheduled loan payments.
- **Alliance membership and offshores:** Treat primary and offshore alliances as one group for permissions and reporting.
- **War room tooling:** Track wars, counters, and plans with alliance-aware filters.
- **Audit logging and rules:** Record security-sensitive actions and configure audit rules and retention.
- **Recruitment and applications:** Manage applicant pipelines and alliance onboarding data.
- **War Simulators and Statistics:** Simulate wars and generate reports for alliance leaders, with leaderboards to encourage raiding.

This application is supposed to help you manage your alliance, so expect a fully-fledged system eventually.

### Alliance membership helper
Many features need to know whether a nation belongs to "our" alliance group. Always resolve
`AllianceMembershipService` instead of reading `PW_ALLIANCE_ID` directly:

```php
$membership = app(\App\Services\AllianceMembershipService::class);

if ($membership->contains($nation->alliance_id)) {
    // Treat this nation as part of our umbrella, including enabled offshores.
}
```

The service merges the primary alliance ID with every enabled offshore and caches the result. When an offshore
is toggled or its alliance changes, the membership cache is refreshed automatically.

### Audit logging
Use `App\Services\AuditLogger` to record security-sensitive or operational actions. The logger automatically
captures request metadata (request id, IP, user agent) and the authenticated actor when available.

Basic usage:

```php
app(\App\Services\AuditLogger::class)->record(
    category: 'finance',
    action: 'withdrawal_approved',
    outcome: 'success',
    severity: 'info',
    subject: $transaction,
    context: [
        'related' => [
            ['type' => 'Account', 'id' => (string) $transaction->from_account_id, 'role' => 'from_account'],
        ],
        'data' => [
            'nation_id' => $transaction->nation_id,
        ],
    ],
    message: 'Withdrawal approved.'
);
```

Conventions:
- Use `recordAfterCommit(...)` for changes wrapped in DB transactions.
- Set `subject` to the primary model affected, and add related entities via the `related` array.
- Use `changes` for before/after values and `data` for structured details (avoid secrets).
- Keep messages short; never include passwords, API tokens, or full request payloads.

### Tech Stack:
- **Backend:** Laravel (PHP 8.4+)
- **Frontend:** Blade with Tailwind CSS (DaisyUI)
- **Database:** MySQL/PostgreSQL
- **Subscriptions:** Node.js + GraphQL
- **Admin Panel:** AdminLTE (Bootstrap 5)

## Installation Guide

### Prerequisites
- **PHP 8.4+**
- **Composer**
- **Node.js 18+**
- **MySQL or PostgreSQL**
- **Redis (Optional, for caching and queue management)**

### Installation Steps
1. Clone the repository:
   ```sh
   git clone https://github.com/Yosodog/Nexus-AMS.git
   cd Nexus-AMS
   ```
2. Install PHP and Node dependencies:
   ```sh
   composer install
   npm install
   ```
3. Copy and configure environment settings:
   ```sh
   cp .env.example .env
   ```
    - Update database credentials (`DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`)
    - Update anything else in `.env` that you need.
4. Generate application key:
   ```sh
   php artisan key:generate
   ```
5. Run database migrations:
   ```sh
   php artisan migrate
   ```
6. Seed initial data:
   ```sh
   php artisan db:seed
   ```
7. Build the frontend assets:
   ```sh
   npm run build
   ```
8. Start the development server:
   ```sh
   php artisan serve
   ```
> Prefer an automated production install? Use the [Nexus-Setup](https://github.com/Yosodog/Nexus-Setup) installer to provision Ubuntu with Nexus AMS, Nexus-AMS-Subs, TLS, Supervisor workers, and scheduler configuration in one run.

## Contributing
We welcome contributions! To get started:
1. Fork the repository
2. Clone your fork:
   ```sh
   git clone https://github.com/YOUR_GITHUB_USERNAME/Nexus-AMS.git
   ```
3. Follow the installation guide above.
4. Create a new branch for your feature/fix:
   ```sh
   git checkout -b feature-name
   ```
5. Commit your changes with a meaningful message:
   ```sh
   git commit -m "Add feature-name"
   ```
6. Push to your branch and create a pull request:
   ```sh
   git push origin feature-name
   ```

All pull requests should follow **PSR-12 coding standards** and include meaningful commit messages.

## Reporting Bugs & Suggesting Features
Found a bug? Have a feature request?
- Open an issue on GitHub: [Nexus AMS Issues](https://github.com/Yosodog/Nexus-AMS/issues)
- Clearly describe the problem, expected behavior, and steps to reproduce.
- Label the issue accordingly (bug, feature request, etc.).

Contributors are encouraged to discuss ideas before implementing major changes.

## Need Help?
For questions or support:
- Contact **Yosodog** on Discord or open an issue on GitHub.

---
**License:** GPL-3.0 License. See `LICENSE` file for details.
