import { expect, test } from '@playwright/test';

const target = {
  nation: {
    id: 9876,
    leader_name: 'Target Leader',
    alliance: { id: 456, name: 'Target Alliance' },
    num_cities: 31,
    last_active: '2026-08-05T12:00:00Z',
    score: 7654.32,
  },
  value: 42157764,
  defensive_wars: 1,
  last_beige: 38750000,
  prediction: {
    expected_net: 12500000,
    gross_loot: 28500000,
    conservative_net: 7600000,
    slot_efficiency: 520000,
    duration_hours: 24,
    win_probability: 0.91,
    confidence: 'high',
    military_suitability: { score: 88 },
    approach: {
      label: 'Ground first',
      reason: 'Use the initial ground attacks to reduce resistance before the finishing attack.',
    },
    approaches: [
      { label: 'Ground first', expected_net: 12500000, duration_hours: 24, win_probability: 0.91, attacks: 4 },
      { label: 'Air supported', expected_net: 9800000, duration_hours: 18, win_probability: 0.86, attacks: 5 },
    ],
    components: {
      nation_victory_loot: 21000000,
      ground_loot: 7500000,
      costs: -6000000,
    },
    loot_resources: { money: 8500000, steel: 4200, food: 18000 },
    cost_resources: { munitions: 1200, gasoline: 800 },
    assumptions: ['Target remains inactive until victory', 'No competing attacker depletes the stockpile'],
    scenarios: [
      { label: 'Target inactive', weight: 0.7, expected_net: 16000000 },
      { label: 'Target returns', weight: 0.3, expected_net: 1000000 },
    ],
  },
  intelligence: {
    observed_at: '2026-09-12T12:00:00Z',
    status: 'current',
  },
};

const successHeaders = () => ({
  'content-type': 'application/json',
  'x-nexus-async-state': 'success',
  'x-nexus-data-stale': 'false',
  'x-nexus-data-updated-at': new Date().toISOString(),
});

const lowerBoundTarget = {
  ...target,
  nation: {
    ...target.nation,
    id: 4321,
    leader_name: 'Lower Bound Target',
  },
  prediction: {
    ...target.prediction,
    expected_net: 7000000,
    gross_loot: 14000000,
    valuation: {
      basis: 'lower_bound',
      lower_bound: 7000000,
      upper_bound: 12000000,
      unknown_components: ['bank loot', 'defender return'],
    },
  },
};

test('raid finder preserves filters and announces successful and filtered-empty states', async ({ page }) => {
  const dialogs: string[] = [];
  page.on('dialog', async (dialog) => {
    dialogs.push(dialog.message());
    await dialog.dismiss();
  });

  await page.route('**/api/v1/defense/raid-finder/**', async (route) => {
    await route.fulfill({
      status: 200,
      headers: successHeaders(),
      body: JSON.stringify([target]),
    });
  });

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');

  await expect(page.getByRole('heading', { name: 'Raid Finder' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Target Leader' })).toBeVisible();
  await expect(page.locator('[data-async-live-region]')).toContainText('Targets ready');

  await page.getByLabel('Leader or alliance').fill('does not match');
  await expect(page.locator('[data-raid-state-panel="filtered_empty"]')).toBeVisible();
  await expect.poll(() => new URL(page.url()).searchParams.get('q')).toBe('does not match');

  await page.reload();
  await expect(page.getByLabel('Leader or alliance')).toHaveValue('does not match');
  await expect(page.locator('[data-raid-state-panel="filtered_empty"]')).toBeVisible();
  expect(dialogs).toEqual([]);
});

test('raid finder distinguishes a successful empty result and exposes its check time', async ({ page }) => {
  await page.route('**/api/v1/defense/raid-finder/**', async (route) => {
    await route.fulfill({
      status: 200,
      headers: successHeaders(),
      body: JSON.stringify([]),
    });
  });

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');

  const emptyState = page.locator('[data-raid-state-panel="empty"]');
  await expect(emptyState).toBeVisible();
  await expect(emptyState).toContainText('No eligible targets');
  await expect(emptyState.locator('time')).toHaveAttribute('datetime');
  await expect(page.locator('[data-async-live-region]')).toContainText('No eligible targets');
});

test('raid finder hides server details and retains a support ID', async ({ page }) => {
  await page.route('**/api/v1/defense/raid-finder/**', async (route) => {
    await route.fulfill({
      status: 503,
      headers: {
        'content-type': 'application/json',
        'retry-after': '1',
        'x-nexus-async-state': 'temporary_failure',
      },
      body: JSON.stringify({
        message: 'SQLSTATE[42S02]: sensitive database details SELECT secret FROM private_table',
        state: 'temporary_failure',
        support_id: 'support-503',
      }),
    });
  });

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');

  const failureState = page.locator('[data-raid-state-panel="temporary_failure"]');
  await expect(failureState).toBeVisible();
  await expect(failureState).toContainText('Support ID: support-503');
  await expect(failureState).toContainText('The request could not be completed. Please try again.');
  await expect(page.locator('body')).not.toContainText('SQLSTATE');
  await expect(page.locator('body')).not.toContainText('private_table');
  await expect(page.locator('[data-async-live-region]')).toContainText('temporarily unavailable');
});

test('raid finder keeps loaded targets readable while offline', async ({ page, context }) => {
  await page.route('**/api/v1/defense/raid-finder/**', async (route) => {
    await route.fulfill({
      status: 200,
      headers: successHeaders(),
      body: JSON.stringify([target]),
    });
  });

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');
  await expect(page.getByRole('link', { name: 'Target Leader' })).toBeVisible();

  await context.setOffline(true);
  await expect(page.locator('[data-raid-state-panel="offline"]')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Target Leader' })).toBeVisible();
  await expect(page.locator('[data-async-global-state="offline"]')).toBeVisible();
  await context.setOffline(false);
});

test('raid finder explains session expiry without stealing focus', async ({ page }) => {
  await page.route('**/api/v1/defense/raid-finder/**', async (route) => {
    await route.fulfill({
      status: 419,
      headers: {
        'content-type': 'application/json',
        'x-nexus-async-state': 'session_expired',
      },
      body: JSON.stringify({
        message: 'Your session expired.',
        state: 'session_expired',
      }),
    });
  });

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');

  await expect(page.locator('[data-raid-state-panel="session_expired"]')).toBeVisible();
  await expect(page.locator('[data-async-global-state="session_expired"]')).toBeVisible();
  await expect(page.locator('[data-async-live-region]')).toContainText('session expired');
  await expect(page.getByLabel('Nation ID')).not.toBeFocused();
});

test('raid finder respects retry timing and prevents duplicate refreshes', async ({ page }) => {
  let requestCount = 0;

  await page.route('**/api/v1/defense/raid-finder/**', async (route) => {
    requestCount += 1;

    if (requestCount === 1) {
      await route.fulfill({
        status: 429,
        headers: {
          'content-type': 'application/json',
          'retry-after': '1',
          'x-nexus-async-state': 'rate_limited',
        },
        body: JSON.stringify({
          message: 'Raid Finder is receiving too many requests. Please wait before retrying.',
          source: 'nexus',
          state: 'rate_limited',
          support_id: 'support-123',
        }),
      });
      return;
    }

    await route.fulfill({
      status: 200,
      headers: successHeaders(),
      body: JSON.stringify([target]),
    });
  });

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');

  const state = page.locator('[data-raid-state-panel="rate_limited"]');
  await expect(state).toBeVisible();
  await expect(state).toContainText('Support ID: support-123');
  await expect(state).toContainText('Refresh temporarily paused');
  await expect(state).not.toContainText('Politics & War');

  const retry = state.getByRole('button');
  await expect(retry).toBeDisabled();
  await page.waitForTimeout(250);
  expect(requestCount).toBe(1);

  await expect(retry).toBeEnabled({ timeout: 2500 });
  await retry.click();

  await expect(page.getByRole('link', { name: 'Target Leader' })).toBeVisible();
  expect(requestCount).toBe(2);
});

test('raid finder ranks prediction returns, filters suitability, and rechecks availability', async ({ page }) => {
  const secondTarget = {
    ...target,
    nation: {
      ...target.nation,
      id: 7654,
      leader_name: 'Second Target',
      alliance: { id: 789, name: 'Second Alliance' },
      num_cities: 28,
    },
    value: 44000000,
    last_beige: 44000000,
    prediction: {
      ...target.prediction,
      expected_net: 8000000,
      gross_loot: 44000000,
      conservative_net: 4000000,
      slot_efficiency: 210000,
      military_suitability: { score: 64 },
    },
  };

  await page.route('**/api/v1/defense/raid-finder/**', async (route) => {
    if (route.request().url().includes('/availability')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ eligible: true, checked_at: '2026-09-13T12:00:00Z', reasons: [] }),
      });
      return;
    }

    await route.fulfill({
      status: 200,
      headers: successHeaders(),
      body: JSON.stringify([target, secondTarget]),
    });
  });

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');

  const rows = page.locator('[data-raid-row]');
  await expect(rows).toHaveCount(2);
  await expect(rows.first()).toContainText('Target Leader');
  await expect(rows.first()).toContainText('$12,500,000');
  await expect(rows.first()).toContainText('Gross $28,500,000');
  await expect(page.locator('[data-raid-sort-summary]')).toHaveText('Sorted by expected net return');

  await rows.first().getByRole('button', { name: 'Inspect' }).click();
  const detail = page.locator('[data-raid-detail-row]').first();
  await expect(detail).toBeVisible();
  await expect(detail).toContainText('Ground first');
  await expect(detail).toContainText('Target remains inactive until victory');
  await expect(detail).toContainText('Nation Victory Loot');
  await expect(detail).toContainText('Steel');
  await expect(detail).toContainText('Target inactive');

  await page.locator('[data-raid-finder]').evaluate((root) => {
    root.setAttribute('data-raid-availability-endpoint', '/api/v1/defense/raid-finder/__NATION__/availability/__TARGET__');
  });
  await detail.getByRole('button', { name: 'Check availability' }).click();
  await expect(detail.locator('[data-raid-availability-status]')).toHaveText('Eligible now');

  await page.getByLabel('Military suitability').selectOption('strong');
  await expect(rows).toHaveCount(1);
  await expect(rows.first()).toContainText('Target Leader');
  await expect.poll(() => new URL(page.url()).searchParams.get('military')).toBe('strong');

  await page.getByLabel('Military suitability').selectOption('');
  await page.getByLabel('Sort by').selectOption('gross');
  await expect(rows).toHaveCount(2);
  await expect(rows.first()).toContainText('Second Target');
  await expect(page.locator('[data-raid-sort-summary]')).toHaveText('Sorted by gross loot');
  await expect.poll(() => new URL(page.url()).searchParams.get('sort')).toBe('gross');

  await page.getByLabel('Minimum expected return').fill('10000000');
  await expect(rows).toHaveCount(1);
  await expect(rows.first()).toContainText('Target Leader');
});

test('raid finder labels lower-bound valuations and lists unknown components', async ({ page }) => {
  await page.route('**/api/v1/defense/raid-finder/**', async (route) => {
    await route.fulfill({
      status: 200,
      headers: successHeaders(),
      body: JSON.stringify([lowerBoundTarget]),
    });
  });

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');

  const row = page.locator('[data-raid-row]').first();
  await expect(row).toContainText('Lower Bound Target');
  await expect(row.locator('[data-raid-valuation-badge]')).toHaveText('Lower bound');
  await expect(row.locator('[data-raid-expected-net]')).toHaveText('$7,000,000');
  await expect(row.locator('[data-raid-gross-loot]')).toHaveText('Gross $14,000,000');

  await row.getByRole('button', { name: 'Inspect' }).click();
  const detail = page.locator('[data-raid-detail-row]').first();
  await expect(detail).toBeVisible();
  await expect(detail.locator('[data-raid-detail-valuation-badge]')).toHaveText('Lower bound');
  await expect(detail.locator('[data-raid-valuation-range]')).toHaveText('Lower-bound estimate $7,000,000 to $12,000,000');
  await expect(detail.locator('[data-raid-unknown-components-section]')).toBeVisible();
  await expect(detail.locator('[data-raid-unknown-components]')).toContainText('bank loot');
  await expect(detail.locator('[data-raid-unknown-components]')).toContainText('defender return');
});

test('raid finder polls a queued refresh without showing a false empty state', async ({ page }) => {
  let requestCount = 0;

  await page.route('**/api/v1/defense/raid-finder/**', async (route) => {
    requestCount += 1;

    if (requestCount === 1) {
      await route.fulfill({
        status: 202,
        headers: {
          ...successHeaders(),
          'retry-after': '2',
          'x-nexus-async-state': 'refreshing',
        },
        body: JSON.stringify({ targets: [], state: 'refreshing' }),
      });
      return;
    }

    await route.fulfill({
      status: 200,
      headers: successHeaders(),
      body: JSON.stringify([target]),
    });
  });

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');

  await expect(page.locator('[data-raid-state-panel="loading"]')).toContainText('still being calculated');
  await expect(page.locator('[data-raid-state-panel="empty"]')).toBeHidden();
  await expect(page.getByRole('link', { name: 'Target Leader' })).toBeVisible({ timeout: 8000 });
  expect(requestCount).toBe(2);
});

test('raid finder keeps stale results visible when refresh is rate limited', async ({ page }) => {
  await page.route('**/api/v1/defense/raid-finder/**', async (route) => {
    await route.fulfill({
      status: 200,
      headers: {
        ...successHeaders(),
        'retry-after': '30',
        'x-nexus-async-state': 'rate_limited',
        'x-nexus-data-stale': 'true',
      },
      body: JSON.stringify([target]),
    });
  });

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');

  await expect(page.getByText('Showing saved targets', { exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Target Leader' })).toBeVisible();
  await expect(page.locator('[data-raid-state-panel="stale"]')).toContainText('rate limited');
});

test('shared async guard blocks a duplicate form submission in the same turn', async ({ page }) => {
  await page.route('**/api/v1/defense/raid-finder/**', async (route) => {
    await route.fulfill({
      status: 200,
      headers: successHeaders(),
      body: JSON.stringify([target]),
    });
  });
  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');

  const allowedSubmissions = await page.evaluate(() => {
    const form = document.createElement('form');
    form.method = 'post';
    const button = document.createElement('button');
    button.type = 'submit';
    button.textContent = 'Submit once';
    form.appendChild(button);
    document.body.appendChild(form);
    (window as Window & { initAppUi?: (root: Document) => void }).initAppUi?.(document);

    let allowed = 0;
    form.addEventListener('submit', (event) => {
      if (!event.defaultPrevented) {
        allowed += 1;
      }

      event.preventDefault();
    });

    form.requestSubmit(button);
    form.requestSubmit(button);

    return allowed;
  });

  expect(allowedSubmissions).toBe(1);
});

test('member can review captured raid predictions', async ({ page }) => {
  await page.goto('/_browser/login/member?redirect=/defense/raid-results');

  await expect(page.getByRole('heading', { name: 'My raid results' })).toBeVisible();
  await expect(page.getByText('No raid predictions yet')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Find a target' })).toHaveAttribute('href', /\/defense\/raid-finder/);
});

test('diagnostic admin can review the rolling raid assessment', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/defense/raid-assessment');

  await expect(page.getByRole('heading', { name: 'Raid prediction assessment' })).toBeVisible();
  await expect(page.getByText('No declarations captured yet')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Raid settings' })).toHaveAttribute('href', /\/admin\/defense\/raids/);
});


test('full offensive slots keep planning targets visible with a warning', async ({ page }) => {
  await page.route('**/api/v1/defense/raid-finder/**', route => route.fulfill({
    status: 200,
    headers: successHeaders(),
    body: JSON.stringify([{ ...target, availability: {
      eligible: false, planning_only: true, offensive_wars: 5, offensive_capacity: 5,
      checked_at: new Date().toISOString(),
      reasons: ['All your offensive slots are occupied.'],
    } }]),
  }));
  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');
  await expect(page.locator('[data-raid-return-status]').first()).toContainText('Planning only · 5/5 offensive slots occupied');
  await expect(page.locator('body')).toContainText('Target Leader');
});

for (const width of [1440, 390]) {
  test(`raid details keep long explanations collapsed at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    await page.route('**/api/v1/defense/raid-finder/**', route => route.fulfill({
      status: 200,
      headers: successHeaders(),
      body: JSON.stringify([{ ...target, prediction: { ...target.prediction,
        assumptions: Array.from({ length: 35 }, (_, i) => `Assumption ${i + 1}: Production and battle costs depend on the observed nation state and available resources.`),
      } }]),
    }));
    await page.goto('/_browser/login/member?redirect=/defense/raid-finder');
    await page.locator('[data-raid-row]').first().getByRole('button', { name: 'Inspect' }).click();
    const detail = page.locator('[data-raid-detail-row]').first();
    await expect(detail.locator('[data-raid-assumptions]')).not.toBeVisible();
    await expect(detail.locator('[data-raid-approach-metrics]')).toContainText('4 attacks');
    await expect(detail.locator('[data-raid-approaches]')).not.toBeVisible();
    await detail.locator('[data-raid-detail-panel]').screenshot({ path: `/tmp/raid-details-${width}.png` });
    await detail.getByText('How this estimate is calculated', { exact: true }).click();
    await expect(detail.locator('[data-raid-assumptions]')).toBeVisible();
    await expect(detail.locator('[data-raid-assumptions] li')).toHaveCount(35);
    await detail.getByText('Compare other approaches', { exact: true }).click();
    await expect(detail.locator('[data-raid-approaches]')).toContainText('Air supported');
    await expect(detail.locator('[data-raid-approaches]')).not.toContainText('Ground first');
  });
}

test('raid finder renders unavailable predictions without failing the refresh', async ({ page }) => {
  await page.route('**/api/v1/defense/raid-finder/**', route => route.fulfill({
    status: 200, headers: successHeaders(),
    body: JSON.stringify([{ ...target, prediction: { ...target.prediction, expected_net: null, approach: null, approaches: [
      { label: 'Ground focused', available: false, expected_net: null, reason: 'No soldiers or tanks are available.' },
    ] } }, { ...target, nation: { ...target.nation, id: 9999 }, prediction: { expected_net: null, confidence: 'unavailable' } }]),
  }));
  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');
  await expect(page.locator('[data-raid-row]')).toHaveCount(2);
  await expect(page.locator('[data-raid-state-panel="temporary_failure"]')).not.toBeVisible();
});

test('raid finder stops polling a stuck calculation', async ({ page }) => {
  await page.clock.install();
  let requests = 0;
  await page.route('**/api/v1/defense/raid-finder/**', route => {
    requests++;
    return route.fulfill({ status: 202, headers: { ...successHeaders(), 'x-nexus-async-state': 'refreshing', 'retry-after': '2' }, body: '[]' });
  });
  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');
  await expect(page.locator('[data-raid-state-panel="loading"]')).toContainText('still being calculated');
  await page.clock.fastForward(181_000);
  await expect(page.locator('[data-raid-state-panel="temporary_failure"]')).toContainText('Automatic checks have stopped');
  const stoppedAt = requests;
  await page.clock.fastForward(60_000);
  expect(requests).toBe(stoppedAt);
});

test('refresh preserves inspected targets and expanded calculation notes', async ({ page }) => {
  let requests = 0;
  await page.route('**/api/v1/defense/raid-finder/**', route => {
    requests++;
    return route.fulfill({ status: 200, headers: successHeaders(), body: JSON.stringify([
      { ...target, prediction: { ...target.prediction, expected_net: requests === 1 ? 12500000 : 13000000 } },
    ]) });
  });
  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');
  await page.locator('[data-raid-row]').first().getByRole('button', { name: 'Inspect' }).click();
  const detail = page.locator('[data-raid-detail-row]').first();
  await detail.getByText('How this estimate is calculated', { exact: true }).click();
  await detail.locator('[data-raid-evidence-section] > summary').click();
  await page.locator('[data-raid-refresh]').click();
  await expect(page.locator('[data-raid-expected-net]').first()).toHaveText('$13,000,000');
  await expect(detail).toBeVisible();
  await expect(detail.locator('[data-raid-assumptions]')).toBeVisible();
  await expect(detail.locator('[data-raid-evidence]')).toBeVisible();
  await expect(page.locator('[data-raid-inspect]').first()).toHaveAttribute('aria-expanded', 'true');
  await page.locator('[data-raid-inspect]').first().click();
  await page.locator('[data-raid-refresh]').click();
  await expect(detail).not.toBeVisible();
});

for (const width of [1440, 390]) {
  test(`calculation evidence shows frozen arithmetic and missing values at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    const recorded = {
      ...target,
      prediction: { ...target.prediction, components: { ...target.prediction.components, costs: -16000000 } },
      calculation: {
        as_of: '2026-09-13T12:00:00Z', prices_at: '2026-09-13T11:00:00Z', model_version: 2, prices: { liquidation: { munitions: 1500, steel: 3000 } },
        coverage: { status: 'unverified', label: 'History coverage is unverified; additional attacks may be missing.' },
        observations: [{ resource: 'munitions', war_id: 12345, observed_at: '2026-09-12T12:00:00Z', looted: 1000, fraction: 0.1, fraction_source: 'formula_defaults', post_loot: 9000, fraction_inputs: { war_type: { value: 'RAID', source: 'attack_observation' }, winner_war_policy: { value: 'NONE', source: 'default' } } }],
        resources: {
          munitions: { reported_loot: 1000, before_loot: 10000, post_loot: 9000, production: width === 390 ? null : 600, depletion: width === 390 ? null : 200, net_change: 400, baseline_balance: 9000, current: 9400, lower: 4400, upper: 19400 },
          steel: { reported_loot: null, before_loot: null, post_loot: null, production: null, depletion: null, current: null, lower: null, upper: null },
        },
        uncertainties: ['Historical policy is unknown; the loot fraction is estimated.', 'Unobserved transfers and spending may change the balance.'],
      },
    };
    await page.route('**/api/v1/defense/raid-finder/**', route => route.fulfill({ status: 200, headers: successHeaders(), body: JSON.stringify([recorded]) }));
    await page.goto('/_browser/login/member?redirect=/defense/raid-finder');
    await page.locator('[data-raid-row]').first().getByRole('button', { name: 'Inspect' }).click();
    const detail = page.locator('[data-raid-detail-row]').first();
    await expect(detail.locator('[data-raid-evidence]')).not.toBeVisible();
    await expect(detail.locator('[data-raid-main-uncertainty]')).toContainText('Historical policy is unknown');
    await detail.locator('[data-raid-evidence-section] > summary').click();
    await expect(detail.getByRole('link', { name: 'War 12345' })).toHaveAttribute('href', 'https://politicsandwar.com/nation/war/timeline/war=12345');
    await expect(detail.locator('[data-raid-evidence]')).toContainText('10%');
    await detail.getByText('Modifier inputs · War 12345', { exact: true }).click();
    await expect(detail.getByRole('table', { name: 'Loot modifier inputs' })).toContainText('Assumed; historical value missing');
    await detail.locator('[data-raid-stockpile-section] > summary').click();
    await expect(detail.locator('[data-raid-stockpile-work]')).toContainText('$14,100,000');
    if (width === 390) await expect(detail.locator('[data-raid-stockpile-work]')).toContainText('Combined modeled change since baseline');
    await expect(detail.locator('[data-raid-stockpile-work]')).toContainText('1 of 2 resources valued');
    await detail.getByText('Show resource quantities', { exact: true }).click();
    await expect(detail.getByRole('table', { name: 'Stockpile resource arithmetic' })).toContainText('9,400');
    await expect(detail.getByRole('table', { name: 'Stockpile resource arithmetic' }).getByRole('row').filter({ hasText: 'Steel' })).toContainText('Unavailable');
    await detail.locator('[data-raid-outcome-section] > summary').click();
    await expect(detail.locator('[data-raid-outcome-work]')).toContainText('An individual attack sequence is unavailable');
    const bounds = await detail.locator('[data-raid-detail-panel]').boundingBox();
    expect(bounds?.width).toBeLessThanOrEqual(width);
    await detail.locator('[data-raid-detail-panel]').screenshot({ path: `/tmp/raid-evidence-${width}.png` });
  });
}

test('full defensive slots never appear as raid suggestions even for planning', async ({ page }) => {
  await page.route('**/api/v1/defense/raid-finder/**', route => route.fulfill({ status: 200, headers: successHeaders(), body: JSON.stringify([
    { ...target, defensive_wars: 3, availability: { eligible: false, planning_only: true, defensive_wars: 3 } },
    { ...target, nation: { ...target.nation, id: 555, leader_name: 'Open target' }, defensive_wars: 2 },
  ]) }));
  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');
  await expect(page.getByRole('link', { name: 'Open target', exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Target Leader', exact: true })).toHaveCount(0);
});

test('availability check removes a target whose defensive slots filled', async ({ page }) => {
  await page.route('**/api/v1/defense/raid-finder/**', route => route.fulfill({ status: 200, headers: successHeaders(), body: JSON.stringify([target]) }));
  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');
  await page.route('**/*availability*', route => route.fulfill({ status: 200, headers: successHeaders(), body: JSON.stringify({ eligible: false, planning_only: false, defensive_wars: 3, reasons: ['All defensive slots are occupied.'] }) }));
  await page.locator('[data-raid-inspect]').first().click();
  await page.getByRole('button', { name: 'Check availability', exact: true }).click();
  await expect(page.getByRole('link', { name: 'Target Leader', exact: true })).toHaveCount(0);
});
