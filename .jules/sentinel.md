# Sentinel Security Journal

## 2025-05-24 - Enforce Config Over Env
**Vulnerability:** Direct 'env()' calls outside of config files.
**Learning:** In Laravel, 'env()' returns null when configuration is cached, leading to application failure or insecure defaults in production.
**Prevention:** Always use 'config()' for all environment-dependent values and ensure they are mapped in 'config/' files.
