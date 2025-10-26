# Nexus AMS

## 🚀 About Nexus AMS
Nexus AMS (Alliance Management System) is an open-source web application designed for managing alliances in the game **Politics & War**. It provides powerful tools for alliance banking, war management, resource tracking, taxation, loans, recruitment, and more. It is intended to be a successor of BK Net, which is over 10 years old at this point, and be fully on par with features.

## ⚠️ Alpha Warning
This project is currently in **Alpha** and is **not** production-ready. Expect breaking changes, incomplete features, and potential security risks. Use at your own risk!

## 💡 Why PHP and Laravel?
Nexus AMS is built using **PHP 8.3+** and **Laravel**, leveraging the framework’s robustness, scalability, and ease of development. Don't hate on PHP/Laravel, it's the best tool for this job. Fight me.

## 🌍 About the Node.js Project (Nexus AMS Subs)
[Nexus AMS Subs](https://github.com/Yosodog/Nexus-AMS-Subs) is a companion **Node.js** service that listens to **Politics & War GraphQL subscriptions** and forwards real-time updates to the Nexus AMS backend. It ensures live updates for nation data, alliances, and wars, etc, reducing the need for constant API polling.

### How it Works:
1. **Node.js Service:** Listens for live events from Politics & War using GraphQL Subscriptions.
2. **Pusher Integration:** Manages real-time updates.
3. **API Service:** Forwards updates to Nexus AMS.

## 🛠️ How Nexus AMS Works
Nexus AMS is structured to be modular and scalable:

### Key Features (In current state):
- **Alliance Banking:** Manage deposits, withdrawals, and transactions securely.
- **Loans:** Manage loans and interest payments.
- **City Grants:** Send and manage city grants.

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

### Tech Stack:
- **Backend:** Laravel (PHP 8.3+)
- **Frontend:** Blade with Tailwind CSS (DaisyUI)
- **Database:** MySQL/PostgreSQL
- **Subscriptions:** Node.js + GraphQL
- **Admin Panel:** AdminLTE (Bootstrap 5)

## 📥 Installation Guide

### Prerequisites
- **PHP 8.3+**
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
6. (Optional) Seed initial data:
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

## 🤝 Contributing
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

## 🐞 Reporting Bugs & Suggesting Features
Found a bug? Have a feature request?
- Open an issue on GitHub: [Nexus AMS Issues](https://github.com/Yosodog/Nexus-AMS/issues)
- Clearly describe the problem, expected behavior, and steps to reproduce.
- Label the issue accordingly (bug, feature request, etc.).

Contributors are encouraged to discuss ideas before implementing major changes.

## ❓ Need Help?
For questions or support, contact **Yosodog** on Discord or open an issue on GitHub.

---
**License:** GPL-3.0 License. See `LICENSE` file for details.

