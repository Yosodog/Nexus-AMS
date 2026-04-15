## 2025-05-15 - Improper Configuration Access
**Vulnerability:** Direct use of `env()` in application logic (Services and Notifications).
**Learning:** Calling `env()` outside of configuration files is a security and stability risk in Laravel. If configuration is cached (`php artisan config:cache`), `env()` calls return `null`, potentially disabling security features or breaking the app.
**Prevention:** Always wrap environment variables in a configuration file (e.g., `config/services.php`) and use the `config()` helper in application code.
