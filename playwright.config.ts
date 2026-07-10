import { defineConfig } from '@playwright/test';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

const phpCommand = process.env.PLAYWRIGHT_PHP_COMMAND?.trim() || 'herd php';
const browserDatabase = process.env.PLAYWRIGHT_DB_DATABASE?.trim()
  || join(tmpdir(), `nexus-ams-browser-${process.pid}.sqlite`);
const browserEnvironment = [
  'APP_ENV=testing',
  'APP_URL=http://127.0.0.1:8011',
  'DB_CONNECTION=sqlite',
  `DB_DATABASE=${browserDatabase}`,
  'PW_ALLIANCE_ID=9001',
  'CACHE_STORE=array',
  'QUEUE_CONNECTION=sync',
  'SESSION_DRIVER=file',
  'SANCTUM_STATEFUL_DOMAINS=127.0.0.1:8011',
  'TELESCOPE_ENABLED=false',
  'PULSE_ENABLED=false',
].join(' ');
const runPhp = (arguments_: string) => `${browserEnvironment} ${phpCommand} ${arguments_}`;

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
        command: `npm run build && ${runPhp('artisan app:prepare-browser-tests --no-interaction')} && ${runPhp('artisan serve --host=127.0.0.1 --port=8011 --tries=1 --no-reload --no-interaction')}`,
        url: 'http://127.0.0.1:8011',
        reuseExistingServer: false,
        timeout: 120_000,
      },
});
