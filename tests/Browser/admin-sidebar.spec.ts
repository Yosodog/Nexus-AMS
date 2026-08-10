import { expect, test } from '@playwright/test';

const favoritesKey = 'nexus.admin.command-palette.favorites.v1';
const recentsKey = 'nexus.admin.command-palette.recents.v1';

test('department navigation exposes category disclosures and opens the active category', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/finance');

  const navigation = page.getByRole('navigation', { name: 'Administrative navigation' });
  const economics = navigation.locator('[data-admin-department="economics"]');
  const treasury = navigation.locator('details[data-admin-navigation-section="economics.treasury"]');
  const defenseOperations = navigation.locator('details[data-admin-navigation-section="defense.operations"]');

  await expect(navigation.getByRole('button', { name: 'Search permitted staff tools' })).toBeVisible();
  await expect(navigation.getByRole('link', { name: /^Work queue/ })).toBeVisible();
  await expect(economics.getByRole('heading', { name: 'Economics' })).toBeVisible();
  await expect(navigation.getByText('Alliance departments', { exact: true })).toHaveCount(0);
  await expect(treasury).toHaveAttribute('open', '');
  await expect(treasury.locator('summary')).toHaveAttribute('aria-expanded', 'true');
  await expect(defenseOperations).not.toHaveAttribute('open', '');

  await defenseOperations.locator('summary').click();
  await expect(defenseOperations).toHaveAttribute('open', '');
  await expect(defenseOperations.locator('summary')).toHaveAttribute('aria-expanded', 'true');
  await expect(treasury).toHaveAttribute('open', '');
});

test('pinned shortcuts show at most five permitted favorites and ignore recents', async ({ page }) => {
  await page.addInitScript(({ favoritesKey: storedFavoritesKey, recentsKey: storedRecentsKey }) => {
    window.localStorage.setItem(storedFavoritesKey, JSON.stringify([
      'members',
      'grants-workspace',
      'finance-ledger',
      'war-support',
      'loans',
      'audits',
    ]));
    window.localStorage.setItem(storedRecentsKey, JSON.stringify([
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
  await expect(quickAccess.locator('[data-admin-navigation-id="roles"]')).toHaveCount(0);
  await expect.poll(() => page.evaluate((key) => JSON.parse(window.localStorage.getItem(key) ?? '[]'), favoritesKey))
    .toEqual(['members', 'grant-programs', 'finance-ledger', 'war-aid', 'loans']);
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
  await expect(quickAccess.getByRole('button', { name: 'Unpin Finance ledger' })).toBeVisible();
});

test('links can be pinned and unpinned directly from the sidebar', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin/finance');

  const treasury = page.locator('[data-admin-navigation-section="economics.treasury"]');
  await treasury.getByRole('button', { name: 'Pin Finance ledger' }).click();

  const quickAccess = page.locator('[data-admin-quick-access]');
  await expect(quickAccess.locator('[data-admin-navigation-id="finance-ledger"]')).toBeVisible();
  await expect(treasury.getByRole('button', { name: 'Unpin Finance ledger' })).toHaveAttribute('aria-pressed', 'true');

  await quickAccess.getByRole('button', { name: 'Unpin Finance ledger' }).click();
  await expect(quickAccess.locator('[data-admin-navigation-id="finance-ledger"]')).toHaveCount(0);
  await expect(treasury.getByRole('button', { name: 'Pin Finance ledger' })).toHaveAttribute('aria-pressed', 'false');
});

test('sidebar warns and preserves existing pins when the five-pin limit is reached', async ({ page }) => {
  const pinnedIds = ['members', 'grant-programs', 'finance-ledger', 'war-aid', 'loans'];
  await page.addInitScript(({ favoritesKey: storedFavoritesKey, pinnedIds: storedPinnedIds }) => {
    window.localStorage.setItem(storedFavoritesKey, JSON.stringify(storedPinnedIds));
  }, { favoritesKey, pinnedIds });

  await page.goto('/_browser/login/admin?redirect=/admin');

  const navigation = page.getByRole('navigation', { name: 'Administrative navigation' });
  await navigation.getByRole('button', { name: 'Pin Overview' }).click();

  const warning = navigation.locator('[data-admin-pin-limit-status]');
  await expect(warning).toBeVisible();
  await expect(warning).toHaveText('You can pin up to 5 links. Unpin one before adding another.');
  await expect(navigation.getByRole('button', { name: 'Pin Overview' })).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('[data-admin-quick-access] [data-admin-navigation-id]')).toHaveCount(5);
  await expect.poll(() => page.evaluate((key) => JSON.parse(window.localStorage.getItem(key) ?? '[]'), favoritesKey))
    .toEqual(pinnedIds);
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

  const defenseOperations = page.locator('details[data-admin-navigation-section="defense.operations"]');
  await defenseOperations.locator('summary').click();

  await expect.poll(() => sidebar.evaluate((element) => element.getBoundingClientRect().width)).toBe(270);
  await expect(defenseOperations).toHaveAttribute('open', '');
  await expect(defenseOperations.locator('summary')).toBeFocused();
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
