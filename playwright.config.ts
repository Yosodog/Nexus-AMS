import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/Browser',
  timeout: 30_000,
  workers: 1,
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8011',
    trace: 'on-first-retry',
  },
  webServer: process.env.PLAYWRIGHT_SKIP_WEBSERVER
    ? undefined
    : {
        command: 'npm run build && APP_ENV=testing APP_URL=http://127.0.0.1:8011 DB_CONNECTION=sqlite DB_DATABASE=/tmp/nexus-ams-browser.sqlite CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=file php artisan app:prepare-browser-tests --no-interaction && APP_ENV=testing APP_URL=http://127.0.0.1:8011 DB_CONNECTION=sqlite DB_DATABASE=/tmp/nexus-ams-browser.sqlite CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=file php -S 127.0.0.1:8011 -t public public/index.php',
        url: 'http://127.0.0.1:8011',
        reuseExistingServer: false,
        timeout: 120_000,
      },
});
