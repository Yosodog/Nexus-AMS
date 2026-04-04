import { expect, test } from '@playwright/test';

test('home page renders', async ({ page }) => {
  await page.goto('/');

  await expect(page.getByText('Applications open')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Start your application' })).toBeVisible();
});
