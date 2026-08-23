import { expect, test, type Page } from '@playwright/test';

const viewports = [
  { width: 390, height: 844 },
  { width: 768, height: 1024 },
  { width: 1024, height: 900 },
  { width: 1440, height: 900 },
];

const expectNoRootOverflow = async (page: Page): Promise<void> => {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
};

test('setup prompt defers, resumes, supports keyboard navigation, and reports validation errors', async ({ page }) => {
  await page.goto('/_browser/login/admin?redirect=/admin');
  await expect(page.getByRole('heading', { name: 'Set up your alliance workspace' })).toBeVisible();

  await page.getByRole('button', { name: 'Not now' }).click();
  await expect(page.getByRole('heading', { name: 'Set up your alliance workspace' })).toHaveCount(0);
  await expect(page.getByText('Setup incomplete.', { exact: false })).toBeVisible();

  await page.getByRole('link', { name: 'Resume setup' }).click();
  await expect(page.getByRole('heading', { name: 'Platform & data' })).toBeVisible();
  await expect(page.getByRole('link', { name: /Platform & data/ })).toHaveAttribute('aria-current', 'step');

  await page.getByRole('link', { name: /Administrator security/ }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Administrator security' })).toBeVisible();

  await page.goto('/admin/setup/recruitment');
  await page.getByLabel('Enable applications').check();
  await page.getByLabel('Approved alliance-position ID').fill('1');
  await page.getByLabel('Approval message').fill('');
  await page.getByRole('button', { name: 'Save and continue' }).click();
  await expect(page.getByText('The approval message field is required.').first()).toBeVisible();
});

for (const viewport of viewports) {
  test(`setup remains contained at ${viewport.width}px`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await page.goto('/_browser/login/admin?redirect=/admin/setup/platform');
    await expect(page.getByRole('heading', { name: 'Platform & data' })).toBeVisible();
    await expectNoRootOverflow(page);
  });
}
