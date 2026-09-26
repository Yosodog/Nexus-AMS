import { expect, test, type Page, type Route } from '@playwright/test';

const row = (overrides: Record<string, unknown> = {}) => ({
  rank: 1,
  nation: {
    id: 9876,
    nation_name: 'Target Nation',
    leader_name: 'Target Leader',
    alliance: { id: 456, name: 'Target Alliance' },
    alliance_position: 'MEMBER',
    num_cities: 31,
    score: 7654.32,
    last_active: new Date(Date.now() - 5 * 86_400_000).toISOString(),
    activity_bucket: 'idle',
    beige_turns: 0,
    defensive_wars: 1,
    soldiers: 120000,
    tanks: 8000,
    aircraft: 2100,
    ships: 75,
    war_policy: 'TURTLE',
  },
  valuation: {
    expected_net: 12500000,
    expected_net_low: 7600000,
    expected_net_high: 18000000,
    gross_loot: 15000000,
    win_probability: 0.97,
    victory_probability: 0.91,
    beige_share: 1,
    expected_attacks: 11,
    duration_hours: 60,
    confidence: 'high',
    components: {
      gross_loot: 15000000, nation_loot: 11000000, ground_loot: 3000000, bank_loot: 1000000, bounty: 0,
      consumables: 400000, military_losses: 1500000, infrastructure_losses: 0, counter_risk: 600000,
    },
    loot_resources: { money: 9000000, steel: 4200 },
    cost_resources: { munitions: 1200, gasoline: 800 },
    stockpile: {
      resources: { money: 80000000, coal: 0, oil: 0, uranium: 0, iron: 0, bauxite: 0, lead: 0, gasoline: 0, munitions: 0, steel: 42000, aluminum: 0, food: 180000 },
      value: 95000000, low_value: 66500000, high_value: 123500000,
      evidence_kind: 'loot', evidence_at: new Date().toISOString(), evidence_age_hours: 140.5,
      retention: 0.8, activity_bucket: 'idle',
    },
    competition: { other_attackers: 1 },
    counter: { probability: 0.12 },
    assumptions: ['Bounties are not included.', '<img src=x onerror=alert(1)>'],
  },
  claim: null,
  ...overrides,
});

const payload = (rows: unknown[]) => ({
  data: rows,
  meta: {
    generated_at: new Date().toISOString(),
    model_version: 'raid-valuation-2026-10',
    prices_at: new Date().toISOString(),
    candidate_count: 214,
    attacker: { id: 1, score: 1500, range_min: 1125, range_max: 3750, offensive_wars: 2, offensive_capacity: 5, planning_only: false },
  },
});

const json = (route: Route, status: number, body: unknown, headers: Record<string, string> = {}) =>
  route.fulfill({ status, contentType: 'application/json', headers, body: JSON.stringify(body) });

const finderRoute = (page: Page, handler: (route: Route, url: URL) => Promise<void>) =>
  page.route(/\/api\/v1\/defense\/raid-finder\/(?!availability|claims)[^?]*(\?.*)?$/, (route) => handler(route, new URL(route.request().url())));

test('raid finder ranks targets, explains the valuation, and escapes API text', async ({ page }) => {
  await finderRoute(page, (route) => json(route, 200, payload([row(), row({ rank: 2, nation: { ...row().nation, id: 5555, leader_name: 'Second Leader' } })])));

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');

  await expect(page.getByRole('heading', { name: 'Raid finder' })).toBeVisible();
  await expect(page.locator('[data-raid-row]')).toHaveCount(2);
  await expect(page.getByRole('link', { name: 'Target Leader' })).toHaveAttribute('href', 'https://politicsandwar.com/nation/id=9876');
  await expect(page.locator('[data-raid-status]')).toContainText('Loaded 2 targets');
  await expect(page.locator('[data-raid-attacker-summary]')).toContainText('offensive slots 2/5');

  const first = page.locator('[data-raid-row]').first();
  await expect(first.locator('[data-raid-expected-net]')).toContainText('12,500,000');
  await expect(first.locator('[data-raid-confidence]')).toHaveText('High');
  await expect(first.getByRole('link', { name: 'Declare' })).toHaveAttribute('href', 'https://politicsandwar.com/nation/war/declare/id=9876');

  const details = first.getByRole('button', { name: 'Details' });
  await details.click();
  await expect(details).toHaveAttribute('aria-expanded', 'true');
  const detail = page.locator('[data-raid-detail-row]').first();
  await expect(detail).toContainText('Victory loot');
  await expect(detail).toContainText('Looted 5.9 days ago');
  await expect(detail).toContainText('1 other attacker');
  await expect(detail.locator('[data-raid-assumptions] li').nth(1)).toHaveText('<img src=x onerror=alert(1)>');
});

test('raid finder sends and remembers filters and refreshes with fresh results', async ({ page }) => {
  const requests: URL[] = [];
  await finderRoute(page, (route, url) => {
    requests.push(url);

    return json(route, 200, payload([]));
  });

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');
  await expect(page.locator('[data-raid-empty]')).toBeVisible();

  await page.getByLabel('Minimum expected profit').fill('5000000');
  await page.getByLabel('Alliance').selectOption('unaligned');
  await page.getByLabel('Hide claimed').check();
  await page.getByRole('button', { name: 'Apply' }).click();
  await expect.poll(() => requests.at(-1)?.searchParams.get('min_expected_net')).toBe('5000000');
  expect(requests.at(-1)?.searchParams.get('alliance_scope')).toBe('unaligned');
  expect(requests.at(-1)?.searchParams.get('hide_claimed')).toBe('1');

  await page.getByRole('button', { name: 'Refresh' }).click();
  await expect.poll(() => requests.at(-1)?.searchParams.get('fresh')).toBe('1');

  await page.reload();
  await expect(page.getByLabel('Minimum expected profit')).toHaveValue('5000000');
  await expect(page.getByLabel('Hide claimed')).toBeChecked();
});

test('raid finder shows a safe error with its support ID', async ({ page }) => {
  await finderRoute(page, (route) => json(route, 503, { message: 'Raid targets are temporarily unavailable.', state: 'temporary_failure', support_id: 'support-123' }));

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');

  const error = page.locator('[data-raid-error]');
  await expect(error).toBeVisible();
  await expect(error).toContainText('Support ID: support-123');
  await expect(page.locator('body')).not.toContainText('SQLSTATE');
});

test('raid finder checks availability and manages claims', async ({ page }) => {
  await finderRoute(page, (route) => json(route, 200, payload([row()])));
  let availabilityCalls = 0;
  await page.route('**/api/v1/defense/raid-finder/availability**', (route) => {
    availabilityCalls += 1;

    return availabilityCalls === 1
      ? json(route, 200, { eligible: false, planning_only: false, reasons: ['All defensive slots are occupied.'], defensive_wars: 3, offensive_wars: 1, offensive_capacity: 5, checked_at: new Date().toISOString() })
      : json(route, 429, { message: 'Politics & War is rate limiting availability checks.', state: 'rate_limited', support_id: 'x' }, { 'Retry-After': '30' });
  });
  let claimed = false;
  await page.route('**/api/v1/defense/raid-finder/claims**', (route) => {
    if (route.request().method() === 'POST' && !claimed) {
      claimed = true;

      return json(route, 201, { data: { id: 7, target_nation_id: 9876, nation_id: 1, leader_name: 'Me', expires_at: new Date(Date.now() + 7_200_000).toISOString(), mine: true } });
    }

    if (route.request().method() === 'DELETE') {
      return route.fulfill({ status: 204 });
    }

    return json(route, 422, { message: 'Another member already claimed this target.', errors: { target_nation_id: ['Another member already claimed this target.'] } });
  });

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');
  const first = page.locator('[data-raid-row]').first();

  await first.getByRole('button', { name: 'Check availability' }).click();
  const availability = page.locator('[data-raid-availability]').first();
  await expect(availability).toContainText('Not available');
  await expect(availability).toContainText('All defensive slots are occupied.');
  await first.getByRole('button', { name: 'Check availability' }).click();
  await expect(availability).toContainText('Try again in 30 seconds.');

  await first.getByRole('button', { name: 'Claim' }).click();
  await expect(first.locator('[data-raid-claim-status]')).toContainText('Claimed by you');
  await first.getByRole('button', { name: 'Release' }).click();
  await expect(first.getByRole('button', { name: 'Claim' })).toBeVisible();
  await first.getByRole('button', { name: 'Claim' }).click();
  await expect(first.locator('[data-raid-claim-status]')).toHaveText('Another member already claimed this target.');
});
