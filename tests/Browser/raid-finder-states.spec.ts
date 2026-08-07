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
};

const successHeaders = () => ({
  'content-type': 'application/json',
  'x-nexus-async-state': 'success',
  'x-nexus-data-stale': 'false',
  'x-nexus-data-updated-at': new Date().toISOString(),
});

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

test('raid finder renders a persistent temporary failure with a support ID', async ({ page }) => {
  await page.route('**/api/v1/defense/raid-finder/**', async (route) => {
    await route.fulfill({
      status: 503,
      headers: {
        'content-type': 'application/json',
        'retry-after': '1',
        'x-nexus-async-state': 'temporary_failure',
      },
      body: JSON.stringify({
        message: 'Raid targets are temporarily unavailable.',
        state: 'temporary_failure',
        support_id: 'support-503',
      }),
    });
  });

  await page.goto('/_browser/login/member?redirect=/defense/raid-finder');

  const failureState = page.locator('[data-raid-state-panel="temporary_failure"]');
  await expect(failureState).toBeVisible();
  await expect(failureState).toContainText('Support ID: support-503');
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
          message: 'Politics & War is rate limiting raid data requests.',
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

  const retry = state.getByRole('button');
  await expect(retry).toBeDisabled();
  await page.waitForTimeout(250);
  expect(requestCount).toBe(1);

  await expect(retry).toBeEnabled({ timeout: 2500 });
  await retry.click();

  await expect(page.getByRole('link', { name: 'Target Leader' })).toBeVisible();
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
