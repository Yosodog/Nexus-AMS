import { expect, test } from '@playwright/test';

test('stored theme applies before styles load and persists across navigation', async ({ page }) => {
  await page.addInitScript(() => {
    if (!window.localStorage.getItem('nexus-theme')) {
      window.localStorage.setItem('nexus-theme', 'night');
    }
  });

  let releaseStyles = (): void => {};
  let styleRequestBlocked = false;
  const styleGate = new Promise<void>((resolve) => {
    releaseStyles = resolve;
  });

  await page.route('**/*.css', async (route) => {
    styleRequestBlocked = true;
    await styleGate;
    await route.continue();
  });

  await page.goto('/', { waitUntil: 'commit' });

  try {
    await expect.poll(() => styleRequestBlocked).toBe(true);
    await expect.poll(async () => page.evaluate(() => ({
      theme: document.documentElement.dataset.theme,
      mode: document.documentElement.dataset.themeMode,
      colorScheme: document.documentElement.style.colorScheme,
    }))).toEqual({
      theme: 'night',
      mode: 'night',
      colorScheme: 'dark',
    });
  } finally {
    releaseStyles();
  }

  await page.waitForLoadState('load');
  await page.unroute('**/*.css');
  await page.reload();

  const themeToggle = page.locator('summary[aria-label="Choose appearance"]');
  const themeControl = page.locator('details.theme-control');
  const systemOption = page.locator('button[data-theme-mode="auto"]');
  const lightOption = page.locator('button[data-theme-mode="light"]');
  const darkOption = page.locator('button[data-theme-mode="night"]');

  await themeToggle.focus();
  await expect(themeToggle).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(themeControl).toHaveAttribute('open', '');
  await expect(darkOption).toHaveAttribute('aria-pressed', 'true');

  await page.keyboard.press('Tab');
  await expect(systemOption).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(lightOption).toBeFocused();
  await page.keyboard.press('Enter');

  await expect(themeControl).not.toHaveAttribute('open', '');
  await expect(lightOption).toHaveAttribute('aria-pressed', 'true');
  await expect.poll(async () => page.evaluate(() => window.localStorage.getItem('nexus-theme'))).toBe('light');
  await expect.poll(async () => page.evaluate(() => ({
    theme: document.documentElement.dataset.theme,
    mode: document.documentElement.dataset.themeMode,
    colorScheme: document.documentElement.style.colorScheme,
  }))).toEqual({
    theme: 'light',
    mode: 'light',
    colorScheme: 'light',
  });

  await page.getByRole('link', { name: 'Apply', exact: true }).first().click();

  await expect(page).toHaveURL(/\/apply$/);
  await expect.poll(async () => page.evaluate(() => window.localStorage.getItem('nexus-theme'))).toBe('light');
  await expect.poll(async () => page.evaluate(() => ({
    theme: document.documentElement.dataset.theme,
    mode: document.documentElement.dataset.themeMode,
  }))).toEqual({ theme: 'light', mode: 'light' });
});

test('skip link and mobile navigation are keyboard operable', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');

  const skipLink = page.getByRole('link', { name: 'Skip to main content' });
  await page.keyboard.press('Tab');
  await expect(skipLink).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('#main-content')).toBeFocused();

  const navigationToggle = page.locator('summary[aria-label="Open navigation"]');
  await navigationToggle.focus();
  await expect(navigationToggle).toBeFocused();
  await page.keyboard.press('Enter');

  const mobileNavigation = page.getByRole('navigation', { name: 'Mobile navigation' });
  const overviewLink = mobileNavigation.getByRole('link', { name: 'Overview' });
  const applyLink = mobileNavigation.getByRole('link', { name: 'Apply', exact: true });
  const signInLink = mobileNavigation.getByRole('link', { name: 'Sign in', exact: true });

  await expect(overviewLink).toBeVisible();
  await page.keyboard.press('Tab');
  await expect(overviewLink).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(applyLink).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(signInLink).toBeFocused();
  await page.keyboard.press('Enter');

  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole('heading', { name: /Sign in to/i })).toBeVisible();
});

test('shared confirmation dialog protects a real destructive admin control', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/defense/rebuilding');

  const protectedAction = page.getByRole('button', { name: 'Reset Cycle' });
  const confirmation = page.getByRole('dialog');

  await protectedAction.click();
  await expect(confirmation).toBeVisible();
  await expect(confirmation.getByRole('heading', { name: 'Reset rebuilding cycle?' })).toBeVisible();
  await expect(confirmation).toContainText('Confirm the current cycle is complete');
  await confirmation.getByRole('button', { name: 'Keep current state' }).click();
  await expect(confirmation).not.toBeVisible();
  await expect(page.getByRole('heading', { name: 'Rebuilding Management' })).toBeVisible();
});

test('night theme persists across authenticated member and admin shells', async ({ page }) => {
  await page.addInitScript(() => window.localStorage.setItem('nexus-theme', 'night'));
  await page.goto('/_browser/login/member?redirect=/user/dashboard');

  await expect.poll(async () => page.evaluate(() => ({
    theme: document.documentElement.dataset.theme,
    mode: document.documentElement.dataset.themeMode,
    colorScheme: document.documentElement.style.colorScheme,
  }))).toEqual({ theme: 'night', mode: 'night', colorScheme: 'dark' });

  await page.goto('/_browser/login/admin?redirect=/admin/finance');
  await expect(page.getByRole('heading', { name: 'Alliance finance ledger' })).toBeVisible();
  await expect.poll(async () => page.evaluate(() => ({
    theme: document.documentElement.dataset.theme,
    mode: document.documentElement.dataset.themeMode,
    colorScheme: document.documentElement.style.colorScheme,
  }))).toEqual({ theme: 'night', mode: 'night', colorScheme: 'dark' });
});
