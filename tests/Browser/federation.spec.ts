import { expect, test, type Page } from '@playwright/test';

const expectNoRootOverflow = async (page: Page): Promise<void> => {
  const overflow = await page.evaluate(() => ({
    document: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    body: document.body.scrollWidth - document.documentElement.clientWidth,
  }));

  expect(overflow.document).toBeLessThanOrEqual(1);
  expect(overflow.body).toBeLessThanOrEqual(1);
};

test('federation administration exposes each guarded workflow at desktop and mobile widths', async ({ page }) => {
  const pageErrors: string[] = [];
  page.on('pageerror', error => pageErrors.push(error.stack ?? error.message));

  await page.goto('/_browser/login/admin?redirect=/admin/federation');

  await expect(page.getByRole('heading', { name: 'Nexus Federation' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Installation identity' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Bilateral links' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Coalitions and capabilities' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Publish war-plan snapshots' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Received plans' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Payload-free diagnostics' })).toBeVisible();
  await expect(page.getByText('Nexus Cloud is not involved.')).toBeVisible();
  await expect(page.getByLabel('Peer HTTPS origin')).toBeVisible();
  await expect(page.getByLabel('Coalition name')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Review exact payload' })).toBeVisible();
  await expectNoRootOverflow(page);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();

  await expect(page.getByRole('heading', { name: 'Nexus Federation' })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Federation sections' })).toBeVisible();
  await expectNoRootOverflow(page);
  expect(pageErrors).toEqual([]);
});
