import { defineConfig } from '@playwright/test';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

const shellEscape = (value: string) => `'${value.replaceAll("'", "'\\''")}'`;
const configuredPhpCommand = process.env.PLAYWRIGHT_PHP_COMMAND?.trim();
const configuredPhpBinary = process.env.PLAYWRIGHT_PHP_BINARY?.trim();
const phpCommand = configuredPhpCommand
  || (configuredPhpBinary ? shellEscape(configuredPhpBinary) : 'herd php');
const browserDatabase = process.env.PLAYWRIGHT_DB_DATABASE?.trim()
  || join(tmpdir(), `nexus-ams-browser-${process.pid}.sqlite`);
const browserEnvironment = [
  'APP_ENV=testing',
  'APP_DEBUG=false',
  'APP_URL=http://localhost:8011',
  'DB_CONNECTION=sqlite',
  `DB_DATABASE=${shellEscape(browserDatabase)}`,
  'PW_ALLIANCE_ID=9001',
  'PW_API_ENDPOINT=https://pw.test/graphql',
  'PW_API_KEY=testing-pw-key',
  'PW_API_MUTATION_KEY=testing-pw-mutation-key',
  'NEXUS_API_TOKEN=testing-nexus-token',
  'DISCORD_BOT_KEY=testing-discord-key',
  'DISCORD_GUILD_ID=123456789012345678',
  'DISCORD_APPLICATION_ID=223456789012345678',
  'DISCORD_CONNECTION_ID=11111111-1111-4111-8111-111111111111',
  'DISCORD_RELAY_PROTOCOL_VERSION=2',
  'DISCORD_CAPABILITIES=relay.proof.v2,queue.connection-context.v1',
  'DISCORD_SUPPORTED_QUEUE_ACTIONS=ALERT_DELIVERY_V1,APPLICATION_DISCORD_RECONCILE,WAR_ALERT,ALLIANCE_DEPARTURE,INACTIVITY_ALERT,MEMBER_PROFILE_SYNC,ALLIANCE_ROLE_REMOVAL,BEIGE_ALERT,CITY_TIER_SYNC,WAR_ROOM_CREATE,WAR_ROOM_ARCHIVE,PRIVATE_NOTIFICATION',
  'MILCOM_V2_ENABLED=true',
  'MILCOM_RULES_CONTRACT_VERIFIED=true',
  'CACHE_STORE=array',
  'MAIL_MAILER=array',
  'QUEUE_CONNECTION=sync',
  'SESSION_DRIVER=file',
  'SANCTUM_STATEFUL_DOMAINS=localhost:8011',
  'TELESCOPE_ENABLED=false',
  'PULSE_ENABLED=false',
].join(' ');
const runPhp = (arguments_: string) => `${browserEnvironment} ${phpCommand} ${arguments_}`;

export default defineConfig({
  testDir: './tests/Browser',
  timeout: 30_000,
  workers: 1,
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8011',
    trace: 'on-first-retry',
  },
  webServer: process.env.PLAYWRIGHT_SKIP_WEBSERVER
    ? undefined
    : {
        command: `npm run build && ${runPhp('artisan app:prepare-browser-tests --no-interaction')} && ${runPhp('artisan serve --host=127.0.0.1 --port=8011 --tries=1 --no-reload --no-interaction')}`,
        url: 'http://localhost:8011',
        reuseExistingServer: false,
        timeout: 120_000,
      },
});
