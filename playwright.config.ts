import { defineConfig } from '@playwright/test';

const shellEscape = (value: string) => `'${value.replaceAll("'", "'\\''")}'`;
const phpBinary = shellEscape(process.env.PLAYWRIGHT_PHP_BINARY ?? 'php');

const browserEnvironment = [
  'APP_ENV=testing',
  'APP_NAME=YosoNET',
  'APP_DEBUG=false',
  'APP_URL=http://127.0.0.1:8011',
  'DB_CONNECTION=sqlite',
  'DB_DATABASE=/tmp/nexus-ams-browser.sqlite',
  'CACHE_STORE=array',
  'MAIL_MAILER=array',
  'QUEUE_CONNECTION=sync',
  'SESSION_DRIVER=file',
  'PW_ALLIANCE_ID=0',
  'PW_API_ENDPOINT=https://pw.test/graphql',
  'PW_API_KEY=testing-pw-key',
  'PW_API_MUTATION_KEY=testing-pw-mutation-key',
  'NEXUS_API_TOKEN=testing-nexus-token',
  'DISCORD_BOT_KEY=testing-discord-key',
  'PULSE_ENABLED=false',
  'TELESCOPE_ENABLED=false',
].join(' ');

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
        command: `npm run build && ${browserEnvironment} ${phpBinary} artisan app:prepare-browser-tests --no-interaction && ${browserEnvironment} ${phpBinary} -S 127.0.0.1:8011 -t public public/index.php`,
        url: 'http://127.0.0.1:8011',
        reuseExistingServer: false,
        timeout: 120_000,
      },
});
