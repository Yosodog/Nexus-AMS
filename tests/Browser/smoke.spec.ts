import { expect, test } from '@playwright/test';

test('home page renders', async ({ page }) => {
  await page.goto('/');

  await expect(page.getByText('Recruiting now')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Start your application' }).first()).toBeVisible();
  await page.getByRole('link', { name: 'Apply' }).first().click();

  await expect(page).toHaveURL(/\/apply$/);
  await expect(page.getByRole('heading', { name: /Your path into/i })).toBeVisible();
});

test('public login route and form actions are interactive', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('link', { name: 'Sign in', exact: true }).first().click();

  await expect(page).toHaveURL(/\/login$/);
  await page.getByLabel('Username').fill('nobody');
  await page.getByLabel('Password').fill('wrong-password');
  await page.getByRole('button', { name: 'Sign in to member app' }).click();

  await expect(page.getByText(/We could not sign you in\./)).toBeVisible();
});

test('verified user can reach settings and api docs', async ({ page }) => {
  await page.goto('/_browser/login/member?redirect=/user/settings');

  await expect(page.getByRole('heading', { name: 'Your settings hub' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'View API documentation' })).toBeVisible();
  await expect(page.locator('script[src*="chart.js"]')).toHaveCount(0);

  await page.getByRole('link', { name: 'View API documentation' }).click();

  await expect(page.getByRole('heading', { name: /API reference/i })).toBeVisible();
  await expect(page.getByText('Authorization: Bearer <token>')).toBeVisible();
});

test('admin can reach the users index', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/users');

  await expect(page.getByText('Manage Users').first()).toBeVisible();
  await expect(page.getByText('User Directory')).toBeVisible();
  await expect(page.getByText('browser.member@example.test')).toBeVisible();
});

test('chart pages load Chart.js once and initialize their canvases', async ({ page }) => {
  const pageErrors: string[] = [];
  page.on('pageerror', error => pageErrors.push(error.stack ?? error.message));

  await page.goto('/_browser/login/admin?redirect=/admin/members');

  await expect(page.locator('script[src*="chart.js"]')).toHaveCount(1);
  await expect(page.locator('#cityTierChart')).toBeVisible();

  const chartState = await page.evaluate(() => {
    const chart = (globalThis as typeof globalThis & {
      Chart?: { getChart: (canvas: HTMLCanvasElement) => unknown };
    }).Chart;
    const canvas = document.querySelector<HTMLCanvasElement>('#cityTierChart');

    return {
      chartAvailable: typeof chart === 'function',
      chartInitialized: Boolean(chart && canvas && chart.getChart(canvas)),
      nexusChartsAvailable: typeof (globalThis as typeof globalThis & { NexusCharts?: unknown }).NexusCharts === 'object',
    };
  });

  expect(pageErrors).toEqual([]);
  expect(chartState).toEqual({
    chartAvailable: true,
    chartInitialized: true,
    nexusChartsAvailable: true,
  });

  await page.goto('/admin/accounts');

  await expect(page.locator('script[src*="chart.js"]')).toHaveCount(1);
  await expect(page.locator('#accountsLiquidityChart')).toBeVisible();

  const accountChartCount = await page.evaluate(() => {
    const chart = (globalThis as typeof globalThis & {
      Chart?: { getChart: (canvas: HTMLCanvasElement) => unknown };
    }).Chart;
    const canvases = [
      '#accountsLiquidityChart',
      '#topBalancesChart',
      '#resourceCushionChart',
    ].map(selector => document.querySelector<HTMLCanvasElement>(selector));

    return canvases.filter(canvas => chart && canvas && chart.getChart(canvas)).length;
  });

  expect(accountChartCount).toBe(3);
  expect(pageErrors).toEqual([]);
});

test('non-admin users are blocked from the admin area', async ({ page }) => {
  const response = await page.goto('/_browser/login/member?redirect=/admin/users');

  expect(response?.status()).toBe(403);
  await expect(page.getByText('You must be an administrator to view this page.')).toBeVisible();
});
