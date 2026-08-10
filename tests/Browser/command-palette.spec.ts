import { expect, test } from '@playwright/test';

const favoritesKey = 'nexus.admin.command-palette.favorites.v1';

test('staff can open, search, favorite, and navigate the command palette by keyboard', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin');

  await page.keyboard.press('Control+k');

  const dialog = page.getByRole('dialog', { name: 'Command palette' });
  const search = dialog.getByRole('combobox');
  await expect(dialog).toBeVisible();
  await expect(search).toBeFocused();

  await search.fill('Finance ledger');
  const financeResult = dialog.getByRole('option', { name: 'Open Finance ledger, Economics' });
  await expect(financeResult).toBeVisible();
  await expect(dialog.getByRole('status')).toContainText('1 result available');

  await search.press('ArrowDown');
  await expect(financeResult).toBeFocused();

  await page.keyboard.press('Escape');
  await expect(dialog).not.toBeVisible();

  await page.keyboard.press('Control+k');
  const overviewFavorite = dialog.getByRole('button', { name: 'Add Overview to favorites' });
  await overviewFavorite.click();
  await expect(dialog.getByRole('button', { name: 'Remove Overview from favorites' })).toHaveAttribute('aria-pressed', 'true');
  await expect(dialog.getByText('Favorite', { exact: true })).toBeVisible();
});

test('command palette warns and preserves existing pins when the five-pin limit is reached', async ({ page }) => {
  const pinnedIds = ['members', 'grant-programs', 'finance-ledger', 'war-aid', 'loans'];
  await page.addInitScript(({ favoritesKey: storedFavoritesKey, pinnedIds: storedPinnedIds }) => {
    window.localStorage.setItem(storedFavoritesKey, JSON.stringify(storedPinnedIds));
  }, { favoritesKey, pinnedIds });

  await page.goto('/_browser/login/admin?redirect=/admin');
  await page.keyboard.press('Control+k');

  const dialog = page.getByRole('dialog', { name: 'Command palette' });
  await dialog.getByRole('combobox').fill('Overview');
  await dialog.getByRole('button', { name: 'Add Overview to favorites' }).click();

  const warning = dialog.locator('[data-command-pin-limit-status]');
  await expect(warning).toBeVisible();
  await expect(warning).toHaveText('You can pin up to 5 links. Unpin one before adding another.');
  await expect(dialog.getByRole('button', { name: 'Add Overview to favorites' })).toHaveAttribute('aria-pressed', 'false');
  await expect.poll(() => page.evaluate((key) => JSON.parse(window.localStorage.getItem(key) ?? '[]'), favoritesKey))
    .toEqual(pinnedIds);
});

test('member entity results are permission scoped and expose read-only destinations', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin');
  await page.keyboard.press('Control+k');

  const dialog = page.getByRole('dialog', { name: 'Command palette' });
  await dialog.getByRole('combobox').fill('Browser Member');

  const memberResult = dialog.getByRole('option', { name: 'Open Browser Member Nation, Member' });
  await expect(memberResult).toBeVisible();
  await expect(memberResult).toHaveAttribute('href', /\/admin\/members\/200001$/);
  await expect(dialog.getByText('This palette never performs mutations.')).toBeVisible();
});

test('limited staff never receive unauthorized command metadata', async ({ page }) => {
  await page.goto('/_browser/login/limited?redirect=/admin');
  await page.keyboard.press('Control+k');

  const dialog = page.getByRole('dialog', { name: 'Command palette' });
  await expect(dialog.getByRole('option', { name: 'Open Users, Administration' })).toBeVisible();
  await expect(dialog.getByText('Finance ledger', { exact: true })).toHaveCount(0);
  await expect(dialog.locator('[data-entity-search-url]')).toHaveCount(0);
});
