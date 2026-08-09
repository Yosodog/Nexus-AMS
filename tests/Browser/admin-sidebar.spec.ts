import { expect, test } from '@playwright/test';

const favoritesKey = 'nexus.admin.command-palette.favorites.v1';
const recentsKey = 'nexus.admin.command-palette.recents.v1';

test('department navigation opens the active area and keeps one disclosure open', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/finance');

  const navigation = page.getByRole('navigation', { name: 'Administrative navigation' });
  const economics = navigation.locator('details[data-admin-department="economics"]');
  const defense = navigation.locator('details[data-admin-department="defense"]');

  await expect(navigation.getByRole('button', { name: 'Search permitted staff tools' })).toBeVisible();
  await expect(navigation.getByRole('link', { name: /^Work queue/ })).toBeVisible();
  await expect(economics).toHaveAttribute('open', '');
  await expect(economics.locator('summary')).toHaveAttribute('aria-expanded', 'true');
  await expect(defense).not.toHaveAttribute('open', '');

  await defense.locator('summary').click();
  await expect(defense).toHaveAttribute('open', '');
  await expect(defense.locator('summary')).toHaveAttribute('aria-expanded', 'true');
  await expect(economics).not.toHaveAttribute('open', '');
  await expect(economics.locator('summary')).toHaveAttribute('aria-expanded', 'false');
});

test('quick access shows at most five permitted favorites and recents', async ({ page }) => {
  await page.addInitScript(({ favoritesKey: storedFavoritesKey, recentsKey: storedRecentsKey }) => {
    window.localStorage.setItem(storedFavoritesKey, JSON.stringify([
      'members',
      'grants-workspace',
      'finance-ledger',
      'war-support',
    ]));
    window.localStorage.setItem(storedRecentsKey, JSON.stringify([
      'finance-ledger',
      'loans',
      'audits',
      'roles',
    ]));
  }, { favoritesKey, recentsKey });

  await page.goto('/_browser/login/admin?redirect=/admin');

  const quickAccess = page.locator('[data-admin-quick-access]');
  const quickLinks = quickAccess.locator('[data-admin-navigation-id]');
  await expect(quickAccess).toBeVisible();
  await expect(quickLinks).toHaveCount(5);
  await expect(quickLinks.nth(0)).toHaveAttribute('data-admin-navigation-id', 'members');
  await expect(quickLinks.nth(1)).toHaveAttribute('data-admin-navigation-id', 'grant-programs');
  await expect(quickLinks.nth(2)).toHaveAttribute('data-admin-navigation-id', 'finance-ledger');
  await expect(quickLinks.nth(3)).toHaveAttribute('data-admin-navigation-id', 'war-aid');
  await expect(quickLinks.nth(4)).toHaveAttribute('data-admin-navigation-id', 'loans');
  await expect(quickAccess.locator('[data-admin-navigation-id="audits"]')).toHaveCount(0);
});

test('favorite changes in the command palette update quick access without a reload', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin');
  await page.keyboard.press('Control+k');

  const dialog = page.getByRole('dialog', { name: 'Command palette' });
  await dialog.getByRole('combobox').fill('Finance ledger');
  await dialog.getByRole('button', { name: 'Add Finance ledger to favorites' }).click();
  await page.keyboard.press('Escape');

  const quickAccess = page.locator('[data-admin-quick-access]');
  await expect(quickAccess.locator('[data-admin-navigation-id="finance-ledger"]')).toBeVisible();
  await expect(quickAccess.locator('[data-admin-quick-access-favorite]:not([hidden])')).toHaveCount(1);
});

test('quick access never exposes destinations outside the current permissions', async ({ page }) => {
  await page.addInitScript(({ favoritesKey: storedFavoritesKey }) => {
    window.localStorage.setItem(storedFavoritesKey, JSON.stringify(['finance-ledger', 'users']));
  }, { favoritesKey });

  await page.goto('/_browser/login/limited?redirect=/admin/users');

  const quickAccess = page.locator('[data-admin-quick-access]');
  await expect(quickAccess.locator('[data-admin-navigation-id="users"]')).toBeVisible();
  await expect(quickAccess.locator('[data-admin-navigation-id="finance-ledger"]')).toHaveCount(0);
});

test('collapsed department controls expand the desktop sidebar before disclosure', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin');

  const sidebar = page.locator('.admin-sidebar');
  const collapseControl = sidebar.locator('.menu a').filter({ hasText: 'Collapse' });
  await collapseControl.click();
  await expect.poll(() => sidebar.evaluate((element) => element.getBoundingClientRect().width)).toBe(62);

  const defense = page.locator('details[data-admin-department="defense"]');
  await defense.locator('summary').click();

  await expect.poll(() => sidebar.evaluate((element) => element.getBoundingClientRect().width)).toBe(270);
  await expect(defense).toHaveAttribute('open', '');
  await expect(defense.locator('summary')).toBeFocused();
});

test('mobile destination clicks close the admin drawer', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/_browser/login/admin?redirect=/admin');

  const drawer = page.locator('#admin-sidebar');
  await page.getByLabel('Open administrative navigation').click();
  await expect(drawer).toBeChecked();

  const overview = page.locator('a[data-admin-navigation-id="overview"]');
  await overview.evaluate((link) => link.addEventListener('click', (event) => event.preventDefault(), { once: true }));
  await overview.click();

  await expect(drawer).not.toBeChecked();
});
